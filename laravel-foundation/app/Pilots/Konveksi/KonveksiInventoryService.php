<?php

namespace App\Pilots\Konveksi;

use App\Audit\AuditLogger;
use App\Auth\LegacyUser;

final class KonveksiInventoryService
{
    public function __construct(
        private readonly KonveksiConnection $konveksi,
        private readonly AuditLogger $audit,
    ) {}

    public function audit(LegacyUser $user, string $companyKey): array
    {
        $connection = $this->konveksi->connection();
        $tableProduk = (string) $connection->table('system_table_map')->where('api_key', 'produk')->value('table_name');
        $tableMaster = (string) $connection->table('system_table_map')->where('api_key', 'master_stok')->value('table_name');
        $tableMut = (string) $connection->table('system_table_map')->where('api_key', 'mutasi_stok')->value('table_name');

        $produk = [];
        if ($tableProduk) {
            $produk = $connection->table($tableProduk)->get()->map(fn ($r) => json_decode((string) $r->_data_json, true))->filter()->values()->all();
        }

        $master = [];
        if ($tableMaster) {
            $master = $connection->table($tableMaster)->get()->map(fn ($r) => json_decode((string) $r->_data_json, true))->filter()->values()->all();
        }

        $mutCount = 0;
        if ($tableMut) {
            $mutCount = $connection->table($tableMut)->count();
        }

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

    public function migrateIds(LegacyUser $user, string $companyKey): array
    {
        $connection = $this->konveksi->connection();
        $tableProduk = (string) $connection->table('system_table_map')->where('api_key', 'produk')->value('table_name');
        $tableMaster = (string) $connection->table('system_table_map')->where('api_key', 'master_stok')->value('table_name');

        if (! $tableProduk || ! $tableMaster) {
            return ['status' => 'success', 'updated' => 0, 'message' => 'Tabel inventori belum dipetakan.'];
        }

        $changed = 0;
        $connection->transaction(function () use ($connection, $tableProduk, $tableMaster, &$changed): void {
            $map = [];
            $produkRows = $connection->table($tableProduk)->get();

            foreach ($produkRows as $row) {
                $dec = json_decode((string) $row->_data_json, true);
                if (is_array($dec)) {
                    if (empty($dec['id_stok_item'])) {
                        $dec['id_stok_item'] = 'STK-'.strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
                        $changed++;
                        $connection->table($tableProduk)->where('_row_id', $row->_row_id)->update([
                            '_data_json' => json_encode($dec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]);
                    }
                    $sku = strtoupper((string) ($dec['kode_sku'] ?? ($dec['sku_varian'] ?? ($dec['kode'] ?? ''))));
                    if ($sku !== '') {
                        $map[$sku] = $dec['id_stok_item'];
                    }
                }
            }

            $masterRows = $connection->table($tableMaster)->get();
            foreach ($masterRows as $row) {
                $dec = json_decode((string) $row->_data_json, true);
                if (is_array($dec)) {
                    if (empty($dec['id_stok_item'])) {
                        $sku = strtoupper((string) ($dec['kode_sku'] ?? ($dec['sku_varian'] ?? ($dec['kode'] ?? ''))));
                        $dec['id_stok_item'] = $map[$sku] ?? ('STK-'.strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)));
                        $changed++;
                        $connection->table($tableMaster)->where('_row_id', $row->_row_id)->update([
                            '_data_json' => json_encode($dec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]);
                    }
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
