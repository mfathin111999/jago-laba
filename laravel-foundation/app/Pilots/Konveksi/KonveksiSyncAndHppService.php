<?php

namespace App\Pilots\Konveksi;

use App\Audit\AuditLogger;
use App\Auth\LegacyUser;
use InvalidArgumentException;
use RuntimeException;

final class KonveksiSyncAndHppService
{
    public function __construct(
        private readonly KonveksiConnection $konveksi,
        private readonly AuditLogger $audit,
    ) {}

    public function syncTab(LegacyUser $user, string $companyKey, string $tab, mixed $payload): array
    {
        $rows = is_string($payload) ? json_decode($payload, true) : $payload;
        if (! is_array($rows)) {
            throw new InvalidArgumentException('Payload syncTab tidak valid.');
        }

        $connection = $this->konveksi->connection();
        $mapping = $connection->table('system_table_map')->where('api_key', $tab)->first();
        if (! $mapping) {
            throw new RuntimeException("Tabel untuk tab {$tab} belum dipetakan.");
        }

        $table = (string) $mapping->table_name;

        $connection->transaction(function () use ($connection, $table, $rows): void {
            $connection->table($table)->delete();
            foreach ($rows as $idx => $r) {
                if (! is_array($r)) {
                    continue;
                }
                $connection->table($table)->insert([
                    '_row_order' => $idx,
                    '_data_json' => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }
        });

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

    public function syncMultiTabs(LegacyUser $user, string $companyKey, mixed $payload): array
    {
        $map = is_string($payload) ? json_decode($payload, true) : $payload;
        if (! is_array($map)) {
            throw new InvalidArgumentException('Payload syncMultiTabs tidak valid.');
        }

        $connection = $this->konveksi->connection();
        $synced = [];

        $connection->transaction(function () use ($connection, $map, &$synced): void {
            foreach ($map as $tab => $rows) {
                if (! is_array($rows)) {
                    continue;
                }
                $mapping = $connection->table('system_table_map')->where('api_key', $tab)->first();
                if (! $mapping) {
                    continue;
                }

                $table = (string) $mapping->table_name;
                $connection->table($table)->delete();
                foreach ($rows as $idx => $r) {
                    if (! is_array($r)) {
                        continue;
                    }
                    $connection->table($table)->insert([
                        '_row_order' => $idx,
                        '_data_json' => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
                }
                $synced[$tab] = count($rows);
            }
        });

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

    public function activateHpp(LegacyUser $user, string $companyKey, array $p): array
    {
        $model = trim((string) ($p['model_key'] ?? ''));
        if ($model === '') {
            throw new RuntimeException('Model HPP wajib dipilih.');
        }

        $bom = (array) ($p['bom_items'] ?? []);
        $material = 0.0;
        foreach ($bom as $it) {
            $qty = (float) ($it['qty_pakai_per_pcs'] ?? ($it['qty_per_unit'] ?? 0));
            $waste = (float) ($it['waste_pct'] ?? 0);
            $price = (float) ($it['harga_satuan_pakai'] ?? ($it['harga_satuan'] ?? 0));
            $eff = $qty * (1 + $waste / 100);
            $material += $eff * $price;
        }

        $sumArr = function (array $a): float {
            $s = 0.0;
            foreach ($a as $v) {
                if (is_numeric($v)) {
                    $s += (float) $v;
                } elseif (is_array($v)) {
                    $s += (float) ($v['total'] ?? ($v['nominal'] ?? 0));
                }
            }

            return $s;
        };

        $upahTotal = $sumArr((array) ($p['upah'] ?? []));
        $packTotal = $sumArr((array) ($p['packaging'] ?? []));
        $manualTotal = $sumArr((array) ($p['biaya_manual'] ?? []));
        $oper = (array) ($p['operasional'] ?? []);
        $op = ($oper['mode'] ?? '') === 'persen'
            ? ($material + $upahTotal) * (float) ($oper['persen'] ?? 0) / 100
            : (float) ($oper['nominal'] ?? 0);

        $hpp = round($material + $upahTotal + $packTotal + $op + $manualTotal);
        if ($hpp <= 0) {
            throw new RuntimeException('Total HPP harus lebih dari 0.');
        }

        $connection = $this->konveksi->connection();
        $tableProduk = (string) $connection->table('system_table_map')->where('api_key', 'produk')->value('table_name');
        $tableHist = (string) $connection->table('system_table_map')->where('api_key', 'hpp_history')->value('table_name');
        $now = now()->toIso8601String();
        $ver = 'V01';

        if ($tableProduk) {
            $connection->transaction(function () use ($connection, $tableProduk, $tableHist, $model, $hpp, $ver, $now): void {
                $rows = $connection->table($tableProduk)->get();
                foreach ($rows as $row) {
                    $dec = json_decode((string) $row->_data_json, true);
                    if (is_array($dec)) {
                        $mk = trim((string) ($dec['model_tas'] ?? ($dec['nama'] ?? '')));
                        if ($mk === $model) {
                            $dec['hpp_produk'] = $hpp;
                            $dec['hpp_version'] = $ver;
                            $dec['hpp_updated_at'] = $now;
                            $connection->table($tableProduk)->where('_row_id', $row->_row_id)->update([
                                '_data_json' => json_encode($dec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            ]);
                        }
                    }
                }

                if ($tableHist && $this->konveksi->connection()->getSchemaBuilder()->hasTable($tableHist)) {
                    $hist = [
                        'id' => 'HIST-'.date('YmdHis').'-'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
                        'model_key' => $model,
                        'version' => $ver,
                        'hpp_baru' => $hpp,
                        'created_at' => $now,
                    ];
                    $connection->table($tableHist)->insert([
                        '_row_order' => 0,
                        '_data_json' => json_encode($hist, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
                }
            });
        }

        $this->audit->record(
            $user,
            'Konveksi',
            'ACTIVATE_HPP',
            $model,
            $model,
            null,
            ['model' => $model, 'hpp' => $hpp, 'version' => $ver],
            "Aktivasi HPP model {$model}: Rp ".number_format($hpp, 0, ',', '.'),
        );

        return [
            'status' => 'success',
            'message' => 'HPP berhasil diaktifkan.',
            'data' => [
                'model_key' => $model,
                'hpp' => $hpp,
                'version' => $ver,
            ],
        ];
    }

    public function syncFromPurchases(LegacyUser $user, string $companyKey): array
    {
        $connection = $this->konveksi->connection();
        $tablePemb = (string) $connection->table('system_table_map')->where('api_key', 'pembelian')->value('table_name');
        $tableHpp = (string) $connection->table('system_table_map')->where('api_key', 'bahan_hpp')->value('table_name');

        if (! $tablePemb || ! $tableHpp) {
            return ['status' => 'success', 'updated' => 0, 'message' => 'Tabel pembelian/HPP belum dipetakan.'];
        }

        $latestPrices = [];
        $pembRows = $connection->table($tablePemb)->get();
        foreach ($pembRows as $row) {
            $dec = json_decode((string) $row->_data_json, true);
            if (is_array($dec) && isset($dec['items']) && is_array($dec['items'])) {
                foreach ($dec['items'] as $it) {
                    $bid = (string) ($it['id_bahan'] ?? '');
                    $price = (float) ($it['harga_satuan'] ?? 0);
                    if ($bid !== '' && $price > 0) {
                        $latestPrices[$bid] = $price;
                    }
                }
            }
        }

        $count = 0;
        $connection->transaction(function () use ($connection, $tableHpp, $latestPrices, &$count): void {
            $hppRows = $connection->table($tableHpp)->get();
            foreach ($hppRows as $row) {
                $dec = json_decode((string) $row->_data_json, true);
                if (is_array($dec)) {
                    $bid = (string) ($dec['id_bahan'] ?? ($dec['id_bahan_gudang'] ?? ''));
                    if (isset($latestPrices[$bid])) {
                        $dec['harga_beli_terakhir'] = $latestPrices[$bid];
                        $dec['price_review_required'] = true;
                        $dec['updated_at'] = now()->toIso8601String();
                        $connection->table($tableHpp)->where('_row_id', $row->_row_id)->update([
                            '_data_json' => json_encode($dec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]);
                        $count++;
                    }
                }
            }
        });

        $this->audit->record(
            $user,
            'Konveksi',
            'SYNC_HPP_PURCHASES',
            'SYNC',
            'PURCHASES',
            null,
            ['updated_count' => $count],
            "Sinkronisasi HPP pembelian untuk {$count} material.",
        );

        return [
            'status' => 'success',
            'message' => "Harga pembelian terbaru berhasil disinkronkan untuk {$count} material.",
            'updated' => $count,
        ];
    }
}
