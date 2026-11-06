<?php

namespace App\Services\Konveksi;

use App\Audit\AuditLogger;
use App\Auth\AuthenticatedUser;
use InvalidArgumentException;
use RuntimeException;

final class KonveksiSyncAndHppService
{
    public function __construct(
        private readonly KonveksiConnection $konveksi,
        private readonly AuditLogger $audit,
        private readonly KonveksiCollections $collections,
        private readonly KonveksiUserAccounts $accounts,
    ) {}

    public function syncTab(AuthenticatedUser $user, string $companyKey, string $tab, mixed $payload): array
    {
        $rows = is_string($payload) ? json_decode($payload, true) : $payload;
        if (! is_array($rows)) {
            throw new InvalidArgumentException('Payload syncTab tidak valid.');
        }

        if (! $this->collections->mapped($tab)) {
            throw new RuntimeException("Tabel untuk tab {$tab} belum dipetakan.");
        }

        $accounts = $tab === KonveksiUserAccounts::COLLECTION ? $this->accounts->protect($rows) : null;
        $rows = $accounts['records'] ?? $rows;
        $this->konveksi->connection()->transaction(function () use ($tab, $rows): void {
            $this->collections->replace($tab, $rows);
        });
        if ($accounts !== null) {
            $this->accounts->mirror($user, $accounts['changed'], $rows, $accounts['previous']);
        }

        $this->audit->record(
            $user,
            'Konveksi',
            'SYNC_TAB',
            $tab,
            $tab,
            null,
            ['rows_count' => count($rows)],
            "Sinkronisasi tab {$tab} (".count($rows).' baris)',
        );

        return ['success' => true, 'tab' => $tab, 'count' => count($rows)];
    }

    public function syncMultiTabs(AuthenticatedUser $user, string $companyKey, mixed $payload): array
    {
        $map = is_string($payload) ? json_decode($payload, true) : $payload;
        if (! is_array($map)) {
            throw new InvalidArgumentException('Payload syncMultiTabs tidak valid.');
        }

        $synced = [];

        $accounts = null;
        $this->konveksi->connection()->transaction(function () use ($map, &$synced, &$accounts): void {
            foreach ($map as $tab => $rows) {
                if (! is_array($rows) || ! $this->collections->mapped((string) $tab)) {
                    continue;
                }
                if ($tab === KonveksiUserAccounts::COLLECTION) {
                    $accounts = $this->accounts->protect($rows);
                    $rows = $accounts['records'];
                }
                $this->collections->replace((string) $tab, $rows);
                $synced[$tab] = count($rows);
            }
        });
        if ($accounts !== null) {
            $this->accounts->mirror($user, $accounts['changed'], $accounts['records'], $accounts['previous']);
        }

        $this->audit->record(
            $user,
            'Konveksi',
            'SYNC_MULTI_TABS',
            'MULTI',
            'TABS',
            null,
            $synced,
            'Sinkronisasi multi-tab: '.implode(', ', array_keys($synced)),
        );

        return ['success' => true, 'synced_tabs' => $synced];
    }

    /**
     * Activates a new HPP (cost price) for every product of a model. The cost is built from the bill of
     * materials, labour, packaging, overhead and manual costs. A material without an active price is refused
     * (otherwise the cost would be understated), the version number increases per model, and the formula
     * snapshot is kept in the history so any HPP can be explained later.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    public function activateHpp(AuthenticatedUser $user, string $companyKey, array $p): array
    {
        $model = trim((string) ($p['model_key'] ?? ''));
        if ($model === '') {
            throw new RuntimeException('Model HPP wajib dipilih.');
        }

        $bom = array_values((array) ($p['bom_items'] ?? []));
        $upah = (array) ($p['upah'] ?? []);
        $oper = (array) ($p['operasional'] ?? []);
        $now = now()->toIso8601String();

        $material = 0.0;
        $bomRows = [];
        foreach ($bom as $index => $rawItem) {
            $item = is_array($rawItem) ? $rawItem : [];
            $qty = (float) ($item['qty_pakai_per_pcs'] ?? $item['qty_per_unit'] ?? 0);
            $waste = (float) ($item['waste_pct'] ?? 0);
            $price = (float) ($item['harga_satuan_pakai'] ?? $item['harga_satuan'] ?? 0);
            $effective = $qty * (1 + $waste / 100);
            $cost = $effective * $price;
            if ($qty > 0 && $price <= 0) {
                throw new RuntimeException('Material '.($item['nama_bahan'] ?? $item['nama_item'] ?? ($index + 1)).' belum memiliki harga aktif.');
            }
            $material += $cost;
            $bomRows[] = array_merge($item, [
                'id_bom' => $item['id_bom'] ?? $this->historyId('BOM'), 'model_key' => $model, 'qty_efektif_per_pcs' => $effective,
                'hpp_material_per_pcs' => $cost, 'urutan' => $index + 1, 'status' => 'Aktif', 'updated_at' => $now, 'created_at' => $item['created_at'] ?? $now,
            ]);
        }

        $upahTotal = $this->sumCosts($upah);
        $packTotal = $this->sumCosts((array) ($p['packaging'] ?? []));
        $manualTotal = $this->sumCosts((array) ($p['biaya_manual'] ?? []));
        $overhead = ($oper['mode'] ?? 'nominal') === 'persen'
            ? ($material + $upahTotal) * (float) ($oper['persen'] ?? 0) / 100
            : (float) ($oper['nominal'] ?? 0);
        $hpp = round($material + $upahTotal + $packTotal + $overhead + $manualTotal);
        if ($hpp <= 0) {
            throw new RuntimeException('Total HPP harus lebih dari 0.');
        }

        $operator = (string) ($user->getUsername() ?: $user->getAuthIdentifier() ?: 'system');

        return $this->konveksi->connection()->transaction(function () use ($model, $p, $upah, $bomRows, $material, $upahTotal, $packTotal, $manualTotal, $overhead, $hpp, $operator, $now, $user): array {
            $history = $this->collections->rows('hpp_history', true);
            $versions = [];
            foreach ($history as $row) {
                if ((string) ($row['data']['model_key'] ?? '') === $model && preg_match('/V(\d+)/i', (string) ($row['data']['version'] ?? ''), $match) === 1) {
                    $versions[] = (int) $match[1];
                }
            }
            $version = 'V'.str_pad((string) (max($versions ?: [0]) + 1), 2, '0', STR_PAD_LEFT);

            $previous = 0.0;
            foreach ($this->collections->rows('produk', true) as $row) {
                $product = $row['data'];
                if (trim((string) ($product['model_tas'] ?? $product['nama'] ?? '')) !== $model) {
                    continue;
                }
                $previous = max($previous, (float) ($product['hpp_produk'] ?? 0));
                $product['hpp_produk'] = $hpp;
                $product['hpp_version'] = $version;
                $product['hpp_updated_at'] = $now;
                if (isset($upah['potong'])) {
                    $product['upah_potong'] = (float) $upah['potong'];
                }
                if (isset($upah['jahit'])) {
                    $product['upah_jahit'] = (float) $upah['jahit'];
                }
                $this->collections->update('produk', $row['id'], $product);
            }

            foreach ($this->collections->rows('hpp_model_bom', true) as $row) {
                if ((string) ($row['data']['model_key'] ?? '') === $model) {
                    $this->collections->delete('hpp_model_bom', $row['id']);
                }
            }
            foreach ($bomRows as $bomRow) {
                $this->collections->insert('hpp_model_bom', $bomRow);
            }

            $entry = [
                'id' => $this->historyId('HIST'), 'tanggal' => $p['effective_date'] ?? date('Y-m-d'), 'model_key' => $model, 'version' => $version,
                'hpp_lama' => $previous, 'hpp_baru' => $hpp, 'material_total' => $material, 'upah_total' => $upahTotal, 'packaging_total' => $packTotal,
                'operasional_total' => $overhead, 'manual_total' => $manualTotal,
                'formula_snapshot' => json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'catatan' => $p['catatan'] ?? '',
                'operator' => $operator, 'created_at' => $now,
            ];
            $this->collections->insert('hpp_history', $entry);

            $this->audit->record($user, 'Konveksi', 'ACTIVATE_HPP', $entry['id'], $model, ['hpp' => $previous], ['hpp' => $hpp, 'version' => $version], "Aktivasi HPP model {$model}: Rp ".number_format($hpp, 0, ',', '.'));

            return ['status' => 'success', 'message' => 'HPP berhasil diaktifkan.', 'data' => ['model_key' => $model, 'hpp' => $hpp, 'version' => $version, 'history' => $entry]];
        });
    }

    /**
     * Copies the latest purchase price of each material onto its HPP record and flags it for review.
     *
     * @return array<string, mixed>
     */
    public function syncFromPurchases(AuthenticatedUser $user, string $companyKey): array
    {
        return $this->konveksi->connection()->transaction(function () use ($user): array {
            $latest = [];
            foreach ($this->collections->rows('pembelian') as $row) {
                foreach ((array) ($row['data']['items'] ?? []) as $item) {
                    $materialId = (string) ($item['id_bahan'] ?? '');
                    if ($materialId === '') {
                        continue;
                    }
                    $price = (float) ($item['harga_satuan'] ?? 0);
                    if ($price <= 0 && ($item['qty'] ?? 0)) {
                        $price = (float) ($item['total_harga'] ?? $item['subtotal'] ?? 0) / max(1, (float) $item['qty']);
                    }
                    if ($price > 0) {
                        $latest[$materialId] = $price;
                    }
                }
            }

            $count = 0;
            $now = now()->toIso8601String();
            foreach ($this->collections->rows('bahan_hpp', true) as $row) {
                $record = $row['data'];
                $materialId = (string) ($record['id_bahan'] ?? $record['id_bahan_gudang'] ?? '');
                if (! isset($latest[$materialId])) {
                    continue;
                }
                $record['harga_beli_terakhir'] = $latest[$materialId];
                $record['price_review_required'] = true;
                $record['updated_at'] = $now;
                $this->collections->update('bahan_hpp', $row['id'], $record);
                $count++;
            }

            $this->audit->record($user, 'Konveksi', 'SYNC_HPP_PURCHASES', 'SYNC', 'PURCHASES', null, ['updated_count' => $count], "Sinkronisasi HPP pembelian untuk {$count} material.");

            return ['status' => 'success', 'message' => "Harga pembelian terbaru berhasil disinkronkan untuk {$count} material.", 'updated' => $count];
        });
    }

    /** @param array<int|string, mixed> $costs numbers, or entries carrying a total/nominal */
    private function sumCosts(array $costs): float
    {
        $sum = 0.0;
        foreach ($costs as $value) {
            if (is_numeric($value)) {
                $sum += (float) $value;
            } elseif (is_array($value)) {
                $sum += (float) ($value['total'] ?? $value['nominal'] ?? 0);
            }
        }

        return $sum;
    }

    private function historyId(string $prefix): string
    {
        return $prefix.'-'.date('YmdHis').'-'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    }
}
