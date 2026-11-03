<?php

namespace App\Pilots\Konveksi;

use App\Audit\AuditLogger;
use App\Auth\LegacyUser;
use InvalidArgumentException;
use RuntimeException;

final class KonveksiPurchaseWriteService
{
    public function __construct(
        private readonly KonveksiConnection $konveksi,
        private readonly AuditLogger $audit,
        private readonly KonveksiDocumentAndAiService $documents,
    ) {}

    /**
     * Re-stores the purchase invoice file that an earlier upload failed to save.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    public function retryDocument(LegacyUser $user, string $companyKey, array $p): array
    {
        $file = $p['file_invoice'] ?? null;
        if (! is_array($file) || empty($file['base64'])) {
            throw new InvalidArgumentException('File faktur tidak tersedia.');
        }

        $transactionId = (string) ($p['id_transaksi'] ?? '');

        return $this->documents->saveBase64Document(
            $user,
            $companyKey,
            (string) $file['base64'],
            (string) ($file['fileName'] ?? 'Faktur_'.$transactionId.'.jpg'),
            'Faktur_Pembelian',
            $transactionId,
        );
    }

    public function saveMaterial(LegacyUser $user, string $companyKey, array $p): array
    {
        $connection = $this->konveksi->connection();
        $tableBahan = (string) $connection->table('system_table_map')->where('api_key', 'bahan')->value('table_name');
        $tableHpp = (string) $connection->table('system_table_map')->where('api_key', 'bahan_hpp')->value('table_name');
        $tableMut = (string) $connection->table('system_table_map')->where('api_key', 'mutasi_stok')->value('table_name');

        if (! $tableBahan || ! $tableHpp) {
            throw new RuntimeException('Tabel bahan/bahan_hpp belum dipetakan.');
        }

        $edit = ! empty($p['is_edit']);
        $id = trim((string) ($p['id_bahan'] ?? $p['id'] ?? ''));
        if ($id === '') {
            $id = 'BHN-'.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
        }

        $kode = strtoupper(trim((string) ($p['kode_bahan'] ?? $p['kode'] ?? '')));
        if ($kode === '') {
            $kode = 'BHN-'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        }

        $now = now()->toIso8601String();
        $operator = (string) ($user->getUsername() ?: $user->getAuthIdentifier() ?: 'system');

        return $connection->transaction(function () use (
            $connection, $tableBahan, $tableHpp, $tableMut, $id, $kode, $edit,
            $p, $now, $operator, $user
        ): array {
            $existingRows = $connection->table($tableBahan)->get();
            $foundRowId = null;
            $oldData = [];

            foreach ($existingRows as $row) {
                $dec = json_decode((string) $row->_data_json, true);
                if (is_array($dec)) {
                    if (strtoupper((string) ($dec['kode_bahan'] ?? $dec['kode'] ?? '')) === $kode && (string) ($dec['id_bahan'] ?? $dec['id'] ?? '') !== $id) {
                        throw new RuntimeException("Kode material sudah digunakan: {$kode}");
                    }
                    if ((string) ($dec['id_bahan'] ?? $dec['id'] ?? '') === $id) {
                        $foundRowId = $row->_row_id;
                        $oldData = $dec;
                    }
                }
            }

            $record = array_merge($oldData, $p, [
                'id' => $id,
                'id_bahan' => $id,
                'kode' => $kode,
                'kode_bahan' => $kode,
                'stok' => $edit ? (float) ($p['stok'] ?? 0) : (float) ($p['stok_awal'] ?? $p['stok'] ?? 0),
                'created_at' => $p['created_at'] ?? ($oldData['created_at'] ?? $now),
                'updated_at' => $now,
            ]);

            if ($foundRowId !== null) {
                $connection->table($tableBahan)->where('_row_id', $foundRowId)->update([
                    '_data_json' => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            } else {
                $connection->table($tableBahan)->insert([
                    '_row_order' => 0,
                    '_data_json' => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }

            $isi = max(1, (float) ($record['isi_satuan_beli'] ?? 1));
            $hargaBeli = (float) ($record['harga_beli'] ?? $record['harga_satuan_beli'] ?? 0);
            $hargaPakai = (float) ($record['harga_satuan_pakai'] ?? ($hargaBeli / $isi));

            $hppRec = [
                'id_bahan_hpp' => 'HPP-'.$id,
                'id_bahan_gudang' => $id,
                'id_bahan' => $id,
                'kode_bahan' => $kode,
                'nama_bahan' => $record['nama_bahan'] ?? ($record['nama'] ?? ''),
                'kategori' => $record['kategori'] ?? '',
                'satuan_beli' => $record['satuan_beli'] ?? ($record['satuan'] ?? 'Roll'),
                'isi_satuan_beli' => $isi,
                'satuan_pakai' => $record['satuan_pakai'] ?? 'Meter',
                'harga_satuan_beli' => $hargaBeli,
                'harga_satuan_pakai' => $hargaPakai,
                'harga_aktif' => $hargaPakai,
                'sumber_harga' => $record['sumber_harga'] ?? 'MANUAL_AWAL',
                'status' => $hargaPakai > 0 ? 'Aktif' : 'Belum Ada Harga',
                'updated_at' => $now,
                'created_at' => $now,
            ];

            $foundHppRowId = null;
            $existingHpp = $connection->table($tableHpp)->get();
            foreach ($existingHpp as $row) {
                $dec = json_decode((string) $row->_data_json, true);
                if (is_array($dec) && ((string) ($dec['id_bahan'] ?? $dec['id_bahan_gudang'] ?? '') === $id)) {
                    $foundHppRowId = $row->_row_id;
                    $hppRec = array_merge($dec, $hppRec);
                    break;
                }
            }

            if ($foundHppRowId !== null) {
                $connection->table($tableHpp)->where('_row_id', $foundHppRowId)->update([
                    '_data_json' => json_encode($hppRec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            } else {
                $connection->table($tableHpp)->insert([
                    '_row_order' => 0,
                    '_data_json' => json_encode($hppRec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }

            $movement = null;
            if (! $edit && (float) ($record['stok'] ?? 0) > 0 && $tableMut) {
                $movement = [
                    'id_mutasi' => 'MUT-'.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(5)), 0, 8)),
                    'tanggal' => now('Asia/Jakarta')->format('Y-m-d'),
                    'waktu' => now('Asia/Jakarta')->format('H:i:s'),
                    'jenis_mutasi' => 'IN_OPENING',
                    'id_bahan' => $id,
                    'kode_sku' => $kode,
                    'qty_masuk' => (float) $record['stok'],
                    'qty_keluar' => 0,
                    'stok_sebelum' => 0,
                    'stok_setelah' => (float) $record['stok'],
                    'sumber_modul' => 'MASTER_BAHAN',
                    'id_referensi' => $id,
                    'idempotency_key' => 'MATERIAL_OPENING|'.$id,
                    'operator' => $operator,
                    'created_at' => $now,
                ];
                $connection->table($tableMut)->insert([
                    '_row_order' => 0,
                    '_data_json' => json_encode($movement, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }

            $this->audit->record(
                $user,
                'Konveksi',
                $edit ? 'UPDATE_MATERIAL' : 'CREATE_MATERIAL',
                $id,
                $kode,
                $oldData ?: null,
                $record,
                $edit ? 'Update material master' : 'Tambah material master',
            );

            return [
                'status' => 'success',
                'action' => $edit ? 'update' : 'create',
                'material' => $record,
                'bahan_hpp' => $hppRec,
                'mutasi' => $movement,
                'mutasi_created' => (bool) $movement,
                'data' => [
                    'material' => $record,
                    'bahan_hpp' => $hppRec,
                    'mutasi' => $movement,
                ],
            ];
        });
    }

    public function saveInvoice(LegacyUser $user, string $companyKey, array $p): array
    {
        $connection = $this->konveksi->connection();
        $tablePemb = (string) $connection->table('system_table_map')->where('api_key', 'pembelian')->value('table_name');
        $tableBahan = (string) $connection->table('system_table_map')->where('api_key', 'bahan')->value('table_name');
        $tableHpp = (string) $connection->table('system_table_map')->where('api_key', 'bahan_hpp')->value('table_name');
        $tableItems = (string) $connection->table('system_table_map')->where('api_key', 'pembelian_item')->value('table_name');

        if (! $tablePemb || ! $tableBahan || ! $tableItems) {
            throw new RuntimeException('Tabel pembelian/bahan belum dipetakan.');
        }

        $id = trim((string) ($p['id_transaksi'] ?? $p['id'] ?? ''));
        if ($id === '') {
            $id = 'PBL-'.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
        }

        $items = (array) ($p['items'] ?? []);
        if ($items === []) {
            throw new InvalidArgumentException('Faktur minimal memiliki 1 item.');
        }

        $now = now()->toIso8601String();
        $invoice = trim((string) ($p['no_faktur_supplier'] ?? ($p['no_faktur'] ?? '')));

        return $connection->transaction(function () use (
            $connection, $tablePemb, $tableItems,
            $id, $invoice, $items, $p, $now, $user
        ): array {
            $existingRows = $connection->table($tablePemb)->get();
            foreach ($existingRows as $row) {
                $dec = json_decode((string) $row->_data_json, true);
                if (is_array($dec) && (string) ($dec['id'] ?? ($dec['id_transaksi'] ?? '')) === $id) {
                    return ['status' => 'success', 'code' => 'DUPLICATE_IGNORED', 'purchase' => $dec, 'data' => $dec, 'message' => 'Faktur sudah diposting sebelumnya.'];
                }
            }

            $total = 0;
            $detailRows = [];

            foreach ($items as $idx => $it) {
                $qtyBeli = (float) ($it['qty'] ?? 0);
                $harga = (float) ($it['harga_satuan'] ?? 0);
                $subtotal = (float) ($it['subtotal'] ?? ($it['total_harga'] ?? ($harga * $qtyBeli)));
                $total += $subtotal;

                $detailRows[] = array_merge($it, [
                    'id_item' => $it['id_item'] ?? ('PBI-'.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(5)), 0, 8))),
                    'id_pembelian' => $id,
                    'no_faktur_supplier' => $invoice,
                    'created_at' => $now,
                ]);
            }

            $record = array_merge($p, [
                'id' => $id,
                'id_transaksi' => $id,
                'items' => $items,
                'total_harga' => (float) ($p['total_tagihan'] ?? ($p['total_harga'] ?? $total)),
                'status' => $p['status'] ?? 'POSTED',
                'created_at' => $p['created_at'] ?? $now,
                'updated_at' => $now,
            ]);

            $connection->table($tablePemb)->insert([
                '_row_order' => 0,
                '_data_json' => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            foreach ($detailRows as $d) {
                $connection->table($tableItems)->insert([
                    '_row_order' => 0,
                    '_data_json' => json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }

            $this->audit->record(
                $user,
                'Konveksi',
                'SAVE_PURCHASE_INVOICE',
                $id,
                $invoice,
                null,
                $record,
                'Catat faktur pembelian bahan baku',
            );

            return [
                'status' => 'success',
                'message' => 'Faktur pembelian berhasil dicatat.',
                'data' => $record,
            ];
        });
    }
}
