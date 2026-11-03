<?php

namespace App\Pilots\Konveksi;

use App\Audit\AuditLogger;
use App\Auth\LegacyUser;
use DomainException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;

final class KonveksiSalesWriteService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly KonveksiPilotReadService $readService,
        private readonly AuditLogger $audit,
    ) {}

    public function saveDraft(LegacyUser $user, string $companyKey, array $data): array
    {
        $id = trim((string) ($data['id_penjualan'] ?? ''));
        if ($id === '') {
            $id = 'SALE-'.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
        }

        $connection = $this->database->connection();
        $tableMapping = $connection->table('system_table_map')->where('api_key', 'penjualan')->first();
        $itemMapping = $connection->table('system_table_map')->where('api_key', 'penjualan_item')->first();

        if (! $tableMapping || ! $itemMapping) {
            throw new RuntimeException('Tabel penjualan belum dipetakan.');
        }

        $salesTable = (string) $tableMapping->table_name;
        $itemsTable = (string) $itemMapping->table_name;

        // Check if existing sale is posted
        $existingRows = $connection->table($salesTable)->get();
        foreach ($existingRows as $row) {
            $decoded = json_decode((string) $row->_data_json, true);
            if (is_array($decoded) && ($decoded['id_penjualan'] ?? '') === $id) {
                if (strtoupper((string) ($decoded['status_transaksi'] ?? '')) !== 'DRAFT') {
                    throw new DomainException('Penjualan terposting tidak dapat dijadikan draft.', 409);
                }
            }
        }

        $no = trim((string) ($data['no_penjualan'] ?? ''));
        if ($no === '') {
            $no = 'DRAFT-'.date('Ymd-His');
        }

        $now = now()->toIso8601String();
        $record = array_merge($data, [
            'id_penjualan' => $id,
            'no_penjualan' => $no,
            'status_transaksi' => 'DRAFT',
            'created_at' => $data['created_at'] ?? $now,
            'updated_at' => $now,
        ]);

        return $connection->transaction(function () use ($connection, $salesTable, $itemsTable, $id, $no, $now, $record, $data, $user): array {
            // Upsert in penjualan table
            $foundId = null;
            $existingSales = $connection->table($salesTable)->get();
            foreach ($existingSales as $row) {
                $dec = json_decode((string) $row->_data_json, true);
                if (is_array($dec) && ($dec['id_penjualan'] ?? '') === $id) {
                    $foundId = $row->_row_id;
                    break;
                }
            }

            if ($foundId !== null) {
                $connection->table($salesTable)->where('_row_id', $foundId)->update([
                    '_data_json' => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            } else {
                $connection->table($salesTable)->insert([
                    '_row_order' => 0,
                    '_data_json' => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }

            // Remove existing items for this sale
            $existingItems = $connection->table($itemsTable)->get();
            foreach ($existingItems as $row) {
                $dec = json_decode((string) $row->_data_json, true);
                if (is_array($dec) && ($dec['id_penjualan'] ?? '') === $id) {
                    $connection->table($itemsTable)->where('_row_id', $row->_row_id)->delete();
                }
            }

            // Insert new items
            $items = (array) ($data['items'] ?? []);
            foreach ($items as $it) {
                if (! is_array($it)) {
                    continue;
                }
                $itemRec = array_merge($it, [
                    'id_item' => $it['id_item'] ?? ('SLI-'.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(5)), 0, 8))),
                    'id_penjualan' => $id,
                    'no_penjualan' => $no,
                    'created_at' => $now,
                ]);
                $connection->table($itemsTable)->insert([
                    '_row_order' => 0,
                    '_data_json' => json_encode($itemRec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }

            $this->audit->record(
                $user,
                'Konveksi',
                'SAVE_SALE_DRAFT',
                $id,
                $no,
                null,
                $record,
                'Simpan draft penjualan konveksi',
            );

            return [
                'status' => 'success',
                'message' => 'Draft penjualan tersimpan.',
                'data' => $record,
            ];
        });
    }

    public function post(LegacyUser $user, string $companyKey, array $data): array
    {
        $items = (array) ($data['items'] ?? []);
        if ($items === []) {
            throw new InvalidArgumentException('Minimal satu item penjualan.');
        }

        $id = trim((string) ($data['id_penjualan'] ?? ''));
        if ($id === '') {
            $id = 'SALE-'.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
        }

        $connection = $this->database->connection();
        $tableMapping = $connection->table('system_table_map')->where('api_key', 'penjualan')->first();
        $itemMapping = $connection->table('system_table_map')->where('api_key', 'penjualan_item')->first();

        if (! $tableMapping || ! $itemMapping) {
            throw new RuntimeException('Tabel penjualan belum dipetakan.');
        }

        $salesTable = (string) $tableMapping->table_name;
        $itemsTable = (string) $itemMapping->table_name;

        // Idempotency check: if already POSTED, return duplicate ignored
        $existingSales = $connection->table($salesTable)->get();
        foreach ($existingSales as $row) {
            $dec = json_decode((string) $row->_data_json, true);
            if (is_array($dec) && ($dec['id_penjualan'] ?? '') === $id && strtoupper((string) ($dec['status_transaksi'] ?? '')) === 'POSTED') {
                return ['status' => 'success', 'code' => 'DUPLICATE_IGNORED', 'data' => $dec];
            }
        }

        $no = trim((string) ($data['no_penjualan'] ?? ''));
        if ($no === '' || str_starts_with($no, 'DRAFT-')) {
            $no = 'PJL-'.date('Ymd').'-'.str_pad((string) (random_int(1, 9999)), 4, '0', STR_PAD_LEFT);
        }

        $now = now()->toIso8601String();
        $record = array_merge($data, [
            'id_penjualan' => $id,
            'no_penjualan' => $no,
            'status_transaksi' => 'POSTED',
            'updated_at' => $now,
        ]);

        return $connection->transaction(function () use ($connection, $salesTable, $itemsTable, $id, $no, $now, $record, $items, $user): array {
            $foundId = null;
            $existing = $connection->table($salesTable)->get();
            foreach ($existing as $row) {
                $dec = json_decode((string) $row->_data_json, true);
                if (is_array($dec) && ($dec['id_penjualan'] ?? '') === $id) {
                    $foundId = $row->_row_id;
                    break;
                }
            }

            if ($foundId !== null) {
                $connection->table($salesTable)->where('_row_id', $foundId)->update([
                    '_data_json' => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            } else {
                $connection->table($salesTable)->insert([
                    '_row_order' => 0,
                    '_data_json' => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }

            // Remove existing items and insert posted items
            $existingItems = $connection->table($itemsTable)->get();
            foreach ($existingItems as $row) {
                $dec = json_decode((string) $row->_data_json, true);
                if (is_array($dec) && ($dec['id_penjualan'] ?? '') === $id) {
                    $connection->table($itemsTable)->where('_row_id', $row->_row_id)->delete();
                }
            }

            foreach ($items as $it) {
                if (! is_array($it)) {
                    continue;
                }
                $itemRec = array_merge($it, [
                    'id_item' => $it['id_item'] ?? ('SLI-'.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(5)), 0, 8))),
                    'id_penjualan' => $id,
                    'no_penjualan' => $no,
                    'created_at' => $now,
                ]);
                $connection->table($itemsTable)->insert([
                    '_row_order' => 0,
                    '_data_json' => json_encode($itemRec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }

            $this->audit->record(
                $user,
                'Konveksi',
                'POST_SALE',
                $id,
                $no,
                null,
                $record,
                'Posting penjualan konveksi',
            );

            return [
                'status' => 'success',
                'message' => 'Penjualan berhasil diposting.',
                'data' => $record,
            ];
        });
    }

    public function correct(LegacyUser $user, string $companyKey, array $p): array
    {
        $id = trim((string) ($p['id_penjualan'] ?? ''));
        if ($id === '') {
            throw new InvalidArgumentException('ID penjualan tidak valid.');
        }

        $connection = $this->database->connection();
        $tableMapping = $connection->table('system_table_map')->where('api_key', 'penjualan')->first();
        $corrMapping = $connection->table('system_table_map')->where('api_key', 'penjualan_koreksi')->first();

        if (! $tableMapping) {
            throw new RuntimeException('Tabel penjualan belum dipetakan.');
        }

        $salesTable = (string) $tableMapping->table_name;
        $corrTable = $corrMapping ? (string) $corrMapping->table_name : null;

        $existingSales = $connection->table($salesTable)->get();
        $foundRowId = null;
        $before = null;

        foreach ($existingSales as $row) {
            $dec = json_decode((string) $row->_data_json, true);
            if (is_array($dec) && (string) ($dec['id_penjualan'] ?? '') === $id) {
                $foundRowId = $row->_row_id;
                $before = $dec;
                break;
            }
        }

        if (! $before) {
            throw new DomainException('Penjualan tidak ditemukan.', 404);
        }

        $now = now()->toIso8601String();
        $operator = (string) ($user->getUsername() ?: $user->getAuthIdentifier() ?: 'system');

        $revNo = 'R01';
        $after = array_merge($before, $p, [
            'updated_at' => $now,
            'revision_no' => $revNo,
            'last_correction_at' => $now,
            'pdf_needs_refresh' => true,
        ]);

        return $connection->transaction(function () use (
            $connection, $salesTable, $corrTable, $foundRowId, $id, $revNo,
            $before, $after, $now, $operator, $p, $user
        ): array {
            $connection->table($salesTable)->where('_row_id', $foundRowId)->update([
                '_data_json' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $corrId = 'CORR-'.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
            $corrRow = [
                'id_koreksi' => $corrId,
                'id_penjualan' => $id,
                'no_penjualan' => $after['no_penjualan'] ?? '',
                'revision_no' => $revNo,
                'tanggal_koreksi' => $now,
                'alasan' => $p['alasan_koreksi'] ?? ($p['alasan'] ?? 'Koreksi Admin'),
                'before_json' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'after_json' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'operator' => $operator,
                'created_at' => $now,
            ];

            if ($corrTable && \Illuminate\Support\Facades\Schema::hasTable($corrTable)) {
                $connection->table($corrTable)->insert([
                    '_row_order' => 0,
                    '_data_json' => json_encode($corrRow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }

            $this->audit->record(
                $user,
                'Konveksi',
                'CORRECT_SALE',
                $id,
                $after['no_penjualan'] ?? '',
                $before,
                $after,
                'Koreksi penjualan konveksi',
            );

            return [
                'status' => 'success',
                'message' => 'Koreksi penjualan tersimpan.',
                'data' => [
                    'revision_no' => $revNo,
                    'correction' => $corrRow,
                ],
            ];
        });
    }

    public function void(LegacyUser $user, string $companyKey, array $p): array
    {
        $id = trim((string) ($p['id_penjualan'] ?? ''));
        if ($id === '') {
            throw new InvalidArgumentException('ID penjualan tidak valid.');
        }

        $connection = $this->database->connection();
        $tableMapping = $connection->table('system_table_map')->where('api_key', 'penjualan')->first();
        if (! $tableMapping) {
            throw new RuntimeException('Tabel penjualan belum dipetakan.');
        }

        $salesTable = (string) $tableMapping->table_name;
        $existingSales = $connection->table($salesTable)->get();
        $foundRowId = null;
        $sale = null;

        foreach ($existingSales as $row) {
            $dec = json_decode((string) $row->_data_json, true);
            if (is_array($dec) && (string) ($dec['id_penjualan'] ?? '') === $id) {
                $foundRowId = $row->_row_id;
                $sale = $dec;
                break;
            }
        }

        if (! $sale) {
            throw new DomainException('Penjualan tidak ditemukan.', 404);
        }

        if (strtoupper((string) ($sale['status_transaksi'] ?? '')) === 'VOID') {
            return ['status' => 'success', 'code' => 'DUPLICATE_IGNORED', 'data' => $sale];
        }

        $now = now()->toIso8601String();
        $operator = (string) ($user->getUsername() ?: $user->getAuthIdentifier() ?: 'system');

        $voided = array_merge($sale, [
            'status_transaksi' => 'VOID',
            'voided_at' => $now,
            'voided_by' => $operator,
            'void_reason' => $p['alasan_void'] ?? ($p['alasan'] ?? 'Pembatalan Transaksi'),
            'updated_at' => $now,
        ]);

        return $connection->transaction(function () use (
            $connection, $salesTable, $foundRowId, $id, $sale, $voided, $user
        ): array {
            $connection->table($salesTable)->where('_row_id', $foundRowId)->update([
                '_data_json' => json_encode($voided, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $this->audit->record(
                $user,
                'Konveksi',
                'VOID_SALE',
                $id,
                $sale['no_penjualan'] ?? '',
                $sale,
                $voided,
                'Pembatalan (VOID) penjualan konveksi',
            );

            return [
                'status' => 'success',
                'message' => 'Penjualan berhasil dibatalkan (VOID).',
                'data' => $voided,
            ];
        });
    }
}
