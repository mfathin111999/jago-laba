<?php

namespace App\Services\Konveksi;

use App\Audit\AuditLogger;
use App\Auth\AuthenticatedUser;

final class KonveksiInventoryService
{
    public function __construct(
        private readonly KonveksiConnection $konveksi,
        private readonly AuditLogger $audit,
        private readonly KonveksiCollections $collections,
    ) {}

    public function audit(AuthenticatedUser $user, string $companyKey): array
    {
        $produk = $this->collections->mapped('produk') ? array_values(array_filter($this->collections->documents('produk'))) : [];
        $master = $this->collections->mapped('master_stok') ? array_values(array_filter($this->collections->documents('master_stok'))) : [];
        $mutCount = $this->collections->mapped('mutasi_stok') ? $this->collections->count('mutasi_stok') : 0;

        $missing = [];
        $dup = [];
        $negative = [];
        $mismatch = [];
        $seen = [];

        foreach ($produk as $p) {
            $sid = (string) ($p['id_stok_item'] ?? '');
            $sku = (string) ($p['kode_sku'] ?? ($p['sku_varian'] ?? ($p['kode'] ?? '')));
            $pid = (string) ($p['id_produk'] ?? ($p['id'] ?? ''));

            if ($sid === '') {
                $missing[] = ['type' => 'PRODUK', 'kode_sku' => $sku, 'id_produk' => $pid];
            } else {
                if (isset($seen[$sid])) {
                    $dup[] = $sid;
                }
                $seen[$sid] = 1;
            }
        }

        foreach ($master as $m) {
            $stock = (float) ($m['stok_akhir'] ?? ($m['stok'] ?? 0));
            $sku = (string) ($m['kode_sku'] ?? ($m['sku_varian'] ?? ($m['kode'] ?? '')));
            $sid = (string) ($m['id_stok_item'] ?? '');

            if ($stock < 0) {
                $negative[] = ['kode_sku' => $sku, 'stok' => $stock];
            }

            foreach ($produk as $p) {
                $pSid = (string) ($p['id_stok_item'] ?? '');
                $pSku = (string) ($p['kode_sku'] ?? ($p['sku_varian'] ?? ($p['kode'] ?? '')));
                if (($sid !== '' && $pSid === $sid) || ($sku !== '' && strcasecmp($pSku, $sku) === 0)) {
                    $ps = (float) ($p['stok'] ?? 0);
                    if (abs($ps - $stock) > 0.00001) {
                        $mismatch[] = ['kode_sku' => $sku, 'produk_stok' => $ps, 'master_stok' => $stock];
                    }
                    break;
                }
            }
        }

        $uniqueDups = array_values(array_unique($dup));
        $status = (! $missing && ! $uniqueDups && ! $negative && ! $mismatch) ? 'PASS' : 'WARNING';

        $report = [
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
            'total_produk' => count($produk),
            'total_master_stok' => count($master),
            'total_mutasi' => $mutCount,
            'missing_id_stok_item' => $missing,
            'duplicate_id_stok_item' => $uniqueDups,
            'negative_stock' => $negative,
            'produk_master_mismatch' => $mismatch,
            'summary' => [
                'missing' => count($missing),
                'duplicate' => count($uniqueDups),
                'negative' => count($negative),
                'mismatch' => count($mismatch),
            ],
        ];

        $this->audit->record(
            $user,
            'Konveksi',
            'AUDIT_INVENTORY',
            'AUDIT',
            $companyKey,
            null,
            $report,
            "Audit konsistensi inventori konveksi: {$status}",
        );

        return $report;
    }

    public function migrateIds(AuthenticatedUser $user, string $companyKey): array
    {
        if (! $this->collections->mapped('produk') || ! $this->collections->mapped('master_stok')) {
            return ['status' => 'success', 'updated' => 0, 'message' => 'Tabel inventori belum dipetakan.'];
        }

        $changed = 0;
        $this->konveksi->connection()->transaction(function () use (&$changed): void {
            $map = [];

            foreach ($this->collections->rows('produk') as $row) {
                $dec = $row['data'];
                if (empty($dec['id_stok_item'])) {
                    $dec['id_stok_item'] = 'STK-'.strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
                    $changed++;
                    $this->collections->update('produk', $row['id'], $dec);
                }
                $sku = strtoupper((string) ($dec['kode_sku'] ?? ($dec['sku_varian'] ?? ($dec['kode'] ?? ''))));
                if ($sku !== '') {
                    $map[$sku] = $dec['id_stok_item'];
                }
            }

            foreach ($this->collections->rows('master_stok') as $row) {
                $dec = $row['data'];
                if (empty($dec['id_stok_item'])) {
                    $sku = strtoupper((string) ($dec['kode_sku'] ?? ($dec['sku_varian'] ?? ($dec['kode'] ?? ''))));
                    $dec['id_stok_item'] = $map[$sku] ?? ('STK-'.strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)));
                    $changed++;
                    $this->collections->update('master_stok', $row['id'], $dec);
                }
            }
        });

        $this->audit->record(
            $user,
            'Konveksi',
            'MIGRATION_ID_STOCK_ITEM',
            'MIGRATE',
            $companyKey,
            null,
            ['changed' => $changed],
            "Migrasi id_stok_item selesai ({$changed} baris diperbarui).",
        );

        return [
            'status' => 'success',
            'code' => 'INVENTORY_ID_MIGRATED',
            'updated' => $changed,
            'message' => 'Migrasi id_stok_item selesai.',
        ];
    }
}
