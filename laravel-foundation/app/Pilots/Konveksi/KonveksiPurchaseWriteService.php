<?php

namespace App\Pilots\Konveksi;

use App\Audit\AuditLogger;
use App\Auth\LegacyUser;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

final class KonveksiPurchaseWriteService
{
    public function __construct(
        private readonly KonveksiConnection $konveksi,
        private readonly AuditLogger $audit,
        private readonly KonveksiDocumentAndAiService $documents,
        private readonly KonveksiCollections $collections,
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

    /**
     * Posts a supplier invoice. Stock of every material goes up with a movement, the last purchase price is
     * recorded for HPP review, what was paid leaves the cash book and the unpaid rest becomes a supplier debt.
     * Reposting the same invoice is ignored; changing a posted invoice is refused. All or nothing.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    public function saveInvoice(LegacyUser $user, string $companyKey, array $p): array
    {
        $items = array_values((array) ($p['items'] ?? []));
        if ($items === []) {
            throw new InvalidArgumentException('Faktur minimal memiliki 1 item.');
        }

        $requestHash = hash('sha256', json_encode($p, JSON_THROW_ON_ERROR));
        $id = trim((string) ($p['id_transaksi'] ?? $p['id'] ?? '')) ?: $this->newId('PBL');
        $invoice = trim((string) ($p['no_faktur_supplier'] ?? $p['no_faktur'] ?? ''));
        $supplier = (string) ($p['id_supplier'] ?? '');
        $operator = (string) ($user->getUsername() ?: $user->getAuthIdentifier() ?: 'system');

        return $this->konveksi->connection()->transaction(function () use ($items, $requestHash, $id, $invoice, $supplier, $operator, $p, $user): array {
            $purchases = $this->collections->rows('pembelian', true);
            foreach ($purchases as $row) {
                $existing = $row['data'];
                if ((string) ($existing['id'] ?? $existing['id_transaksi'] ?? '') !== $id) {
                    continue;
                }
                if (isset($existing['request_hash']) && hash_equals((string) $existing['request_hash'], $requestHash)) {
                    return ['status' => 'success', 'code' => 'DUPLICATE_IGNORED', 'purchase' => $existing, 'data' => $existing, 'message' => 'Faktur sudah diposting sebelumnya.'];
                }

                throw new DomainException('Faktur terposting tidak boleh ditimpa. Gunakan koreksi atau retur.', 409);
            }
            if ($invoice !== '') {
                foreach ($purchases as $row) {
                    $other = $row['data'];
                    if ((string) ($other['id_supplier'] ?? '') === $supplier
                        && strcasecmp((string) ($other['no_faktur_supplier'] ?? $other['no_faktur'] ?? ''), $invoice) === 0
                        && (string) ($other['id'] ?? $other['id_transaksi'] ?? '') !== $id) {
                        throw new RuntimeException('Faktur supplier ini sudah pernah dicatat.');
                    }
                }
            }

            $materials = $this->collections->rows('bahan', true);
            $prices = $this->collections->rows('bahan_hpp', true);
            $dirtyMaterials = [];
            $dirtyPrices = [];
            $total = 0.0;
            $updates = [];
            $movements = [];
            $detailRows = [];
            $now = now()->toIso8601String();
            $date = (string) ($p['tanggal'] ?? date('Y-m-d'));

            foreach ($items as $index => $rawItem) {
                $item = is_array($rawItem) ? $rawItem : [];
                $materialId = (string) ($item['id_bahan'] ?? '');
                if ($materialId === '') {
                    throw new RuntimeException('Item #'.($index + 1).' belum dipetakan ke Master Bahan.');
                }
                $mi = $this->indexOf($materials, ['id_bahan', 'id'], $materialId);
                if ($mi < 0) {
                    throw new RuntimeException('Material tidak ditemukan: '.$materialId);
                }

                $qtyBought = (float) ($item['qty'] ?? 0);
                $factor = max(0.0000001, (float) ($item['conversion_factor'] ?? $item['isi_satuan_beli'] ?? 1));
                $qtyStock = (float) ($item['qty_satuan_pakai'] ?? $item['qty_stok'] ?? ($qtyBought * $factor));
                $price = (float) ($item['harga_satuan'] ?? 0);
                $subtotal = (float) ($item['subtotal'] ?? $item['total_harga'] ?? ($price * $qtyBought));
                if (! is_finite($qtyBought) || $qtyBought <= 0 || ! is_finite($qtyStock) || $qtyStock <= 0 || $price < 0 || $subtotal < 0) {
                    throw new InvalidArgumentException('Kuantitas dan harga pembelian tidak valid.');
                }

                $total += $subtotal;
                $before = (float) ($materials[$mi]['data']['stok'] ?? 0);
                $after = $before + $qtyStock;
                $materials[$mi]['data']['stok'] = $after;
                $materials[$mi]['data']['harga_beli'] = $price;
                $materials[$mi]['data']['updated_at'] = $now;
                $dirtyMaterials[$mi] = true;

                $movements[] = [
                    'id_mutasi' => $this->newId('MUT'), 'tanggal' => $date, 'waktu' => date('H:i:s'), 'jenis_mutasi' => 'IN_PURCHASE',
                    'id_bahan' => $materialId, 'kode_sku' => $materials[$mi]['data']['kode_bahan'] ?? $materials[$mi]['data']['kode'] ?? '',
                    'qty_masuk' => $qtyStock, 'qty_keluar' => 0, 'stok_sebelum' => $before, 'stok_setelah' => $after, 'sumber_modul' => 'PEMBELIAN',
                    'id_referensi' => $id, 'no_referensi' => $invoice ?: $id, 'idempotency_key' => 'PURCHASE|'.$id.'|'.$materialId.'|'.$index,
                    'operator' => $operator, 'created_at' => $now,
                ];
                $updates[] = ['id_bahan' => $materialId, 'stok_sebelum' => $before, 'stok_setelah' => $after, 'harga_terakhir' => $price];

                $pi = $this->indexOf($prices, ['id_bahan', 'id_bahan_gudang'], $materialId);
                if ($pi >= 0) {
                    $prices[$pi]['data']['harga_beli_terakhir'] = $price;
                    $prices[$pi]['data']['price_review_required'] = true;
                    $prices[$pi]['data']['updated_at'] = $now;
                    $dirtyPrices[$pi] = true;
                }

                $detailRows[] = array_merge($item, [
                    'id_item' => $item['id_item'] ?? $this->newId('PBI'), 'id_pembelian' => $id, 'no_faktur_supplier' => $invoice, 'created_at' => $now,
                ]);
            }

            $record = array_merge($p, [
                'request_hash' => $requestHash, 'id' => $id, 'id_transaksi' => $id, 'items' => $items,
                'total_harga' => (float) ($p['total_tagihan'] ?? $p['total_harga'] ?? $total),
                'status' => $p['status'] ?? 'POSTED', 'created_at' => $p['created_at'] ?? $now, 'updated_at' => $now,
            ]);

            $paid = (float) ($p['nominal_bayar'] ?? 0);
            if ($paid < 0 || $paid > (float) $record['total_harga']) {
                throw new InvalidArgumentException('Pembayaran tidak boleh negatif atau melebihi total faktur.');
            }
            if ($paid > 0 && empty($p['id_rekening'])) {
                throw new InvalidArgumentException('Rekening pembayaran wajib dipilih.');
            }
            $remaining = max(0.0, (float) $record['total_harga'] - $paid);
            if ($remaining > 0 && ! $this->collections->available('hutangsupplier')) {
                throw new RuntimeException('Tabel hutang supplier belum dipetakan.');
            }

            // Every check has passed: from here on only writes.
            $this->saveCollection('bahan', $materials, array_keys($dirtyMaterials));
            $this->saveCollection('bahan_hpp', $prices, array_keys($dirtyPrices));
            foreach ($movements as $movement) {
                $this->collections->insert('mutasi_stok', $movement);
            }
            $this->collections->insert('pembelian', $record);
            foreach ($detailRows as $detail) {
                $this->collections->insert('pembelian_item', $detail);
            }

            $journal = null;
            if ($paid > 0) {
                $journal = [
                    'id' => $this->newId('K'), 'tanggal' => $date, 'tipe' => 'Keluar', 'nominal' => $paid, 'kategori' => 'Pembelian Bahan',
                    'modul' => 'Pembelian', 'id_rekening' => $p['id_rekening'] ?? '', 'id_referensi' => $id, 'no_referensi' => $invoice ?: $id,
                    'idempotency_key' => 'FINANCE|PURCHASE|'.$id, 'keterangan' => 'Pembelian bahan '.($invoice ?: $id), 'created_at' => $now, 'created_by' => $operator,
                ];
                $this->collections->insert('keuangan', $journal);
            }
            if ($remaining > 0) {
                $this->collections->insert('hutangsupplier', [
                    'id_hutang' => $this->newId('HTS'), 'id_supplier' => $supplier, 'nama_supplier' => $p['nama_supplier'] ?? '', 'id_pembelian' => $id,
                    'no_referensi' => $invoice ?: $id, 'tanggal' => $date, 'jatuh_tempo' => $p['jatuh_tempo'] ?? '', 'total_hutang' => $remaining,
                    'saldo_hutang' => $remaining, 'status' => 'Aktif', 'created_at' => $now,
                ]);
            }

            $this->audit->record($user, 'Konveksi', 'POST_PURCHASE', $id, $invoice, null, $record, 'Posting faktur pembelian bahan baku');

            return [
                'status' => 'success', 'message' => 'Faktur pembelian berhasil diposting.', 'purchase' => $record, 'transaksi' => $record, 'data' => $record,
                'items_count' => count($items), 'stok_updates' => $updates, 'mutasi_entries' => $movements, 'jurnal_kas' => $journal,
                'nominal_bayar' => $paid, 'new_materials' => [], 'new_aliases' => [],
            ];
        });
    }

    /** @param list<array{id: int|string|null, data: array<string, mixed>}> $rows */
    private function saveCollection(string $key, array $rows, array $dirty): void
    {
        foreach ($dirty as $index) {
            $this->collections->update($key, $rows[$index]['id'], $rows[$index]['data']);
        }
    }

    /**
     * @param  list<array{id: int|string|null, data: array<string, mixed>}>  $rows
     * @param  list<string>  $fields
     */
    private function indexOf(array $rows, array $fields, string $value): int
    {
        foreach ($rows as $index => $row) {
            foreach ($fields as $field) {
                if (isset($row['data'][$field])) {
                    if ((string) $row['data'][$field] === $value) {
                        return $index;
                    }
                    break;
                }
            }
        }

        return -1;
    }

    private function newId(string $prefix): string
    {
        return $prefix.'-'.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
    }
}
