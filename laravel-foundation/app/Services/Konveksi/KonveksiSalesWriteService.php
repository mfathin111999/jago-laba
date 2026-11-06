<?php

namespace App\Services\Konveksi;

use App\Audit\AuditLogger;
use App\Auth\AuthenticatedUser;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

final class KonveksiSalesWriteService
{
    public function __construct(
        private readonly KonveksiConnection $konveksi,
        private readonly KonveksiPilotReadService $readService,
        private readonly KonveksiCollections $collections,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Marks a sale's backup as pending again so the next backup run picks it up.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    public function retryBackup(AuthenticatedUser $user, string $companyKey, array $p): array
    {
        $id = trim((string) ($p['id_penjualan'] ?? ''));
        if ($id === '') {
            throw new InvalidArgumentException('ID penjualan tidak valid.');
        }

        if (! $this->collections->mapped('penjualan')) {
            throw new RuntimeException('Tabel penjualan belum dipetakan.');
        }

        return $this->konveksi->connection()->transaction(function () use ($id, $user): array {
            foreach ($this->collections->rows('penjualan', true) as $row) {
                $sale = $row['data'];
                if ((string) ($sale['id_penjualan'] ?? '') !== $id) {
                    continue;
                }

                $updated = array_merge($sale, ['backup_status' => 'PENDING', 'backup_at' => now()->toIso8601String()]);
                $this->collections->update('penjualan', $row['id'], $updated);
                $this->audit->record($user, 'Konveksi', 'RETRY_SALE_BACKUP', $id, (string) ($sale['no_penjualan'] ?? ''), $sale, $updated, 'Backup transaksi penjualan dijadwalkan ulang');

                return ['status' => 'success', 'message' => 'Status backup transaksi dijadwalkan ulang.', 'data' => $updated];
            }

            throw new DomainException('Penjualan tidak ditemukan.', 404);
        });
    }

    public function saveDraft(AuthenticatedUser $user, string $companyKey, array $data): array
    {
        $id = trim((string) ($data['id_penjualan'] ?? ''));
        if ($id === '') {
            $id = 'SALE-'.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
        }

        if (! $this->collections->mapped('penjualan') || ! $this->collections->mapped('penjualan_item')) {
            throw new RuntimeException('Tabel penjualan belum dipetakan.');
        }

        // Check if existing sale is posted
        foreach ($this->collections->documents('penjualan') as $decoded) {
            if (($decoded['id_penjualan'] ?? '') === $id && strtoupper((string) ($decoded['status_transaksi'] ?? '')) !== 'DRAFT') {
                throw new DomainException('Penjualan terposting tidak dapat dijadikan draft.', 409);
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

        return $this->konveksi->connection()->transaction(function () use ($id, $no, $now, $record, $data, $user): array {
            // Upsert in penjualan table
            $foundId = null;
            foreach ($this->collections->rows('penjualan') as $row) {
                if (($row['data']['id_penjualan'] ?? '') === $id) {
                    $foundId = $row['id'];
                    break;
                }
            }

            if ($foundId !== null) {
                $this->collections->update('penjualan', $foundId, $record);
            } else {
                $this->collections->insert('penjualan', $record);
            }

            // Remove existing items for this sale
            foreach ($this->collections->rows('penjualan_item') as $row) {
                if (($row['data']['id_penjualan'] ?? '') === $id) {
                    $this->collections->delete('penjualan_item', $row['id']);
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
                $this->collections->insert('penjualan_item', $itemRec);
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

    /**
     * Fields of a posted sale that can only change through a reversal (void) and a new posting.
     *
     * @var list<string>
     */
    private const POSTED_LOCKED_FIELDS = ['items', 'nominal_dibayar', 'id_rekening', 'total_harga', 'total_tagihan', 'total_qty', 'status_transaksi', 'id_penjualan', 'no_penjualan', 'tanggal'];

    /**
     * Fields a correction may never set, because they describe the posting or voiding itself.
     *
     * @var list<string>
     */
    private const CORRECTION_PROTECTED_FIELDS = ['status_transaksi', 'posted_at', 'posted_by', 'void_at', 'void_by', 'void_reason', 'idempotency_key', 'revision_no'];

    /**
     * Posts a sale. Everything the screen shows as a result is recomputed here: stock is checked and deducted,
     * totals, HPP snapshot and profit are calculated from server data in whole cents, a stock movement is
     * recorded for each line and the received payment goes to the cash book. Either all of it is saved or none.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    public function post(AuthenticatedUser $user, string $companyKey, array $p): array
    {
        $items = array_values((array) ($p['items'] ?? []));
        if ($items === []) {
            throw new RuntimeException('Minimal satu item penjualan.');
        }

        $id = trim((string) ($p['id_penjualan'] ?? '')) ?: $this->newId('SALE');
        $operator = $this->operatorName($user);

        return $this->konveksi->connection()->transaction(function () use ($items, $id, $p, $user, $operator): array {
            $sales = $this->collections->rows('penjualan', true);
            foreach ($sales as $row) {
                if ((string) ($row['data']['id_penjualan'] ?? '') === $id && strtoupper((string) ($row['data']['status_transaksi'] ?? '')) === 'POSTED') {
                    return ['status' => 'success', 'code' => 'DUPLICATE_IGNORED', 'data' => $row['data']];
                }
            }

            $no = trim((string) ($p['no_penjualan'] ?? ''));
            if ($no === '' || str_starts_with($no, 'DRAFT-')) {
                $no = $this->nextNumber($sales);
            }

            $master = $this->collections->rows('master_stok', true);
            $produk = $this->collections->rows('produk', true);
            $dirtyMaster = [];
            $dirtyProduk = [];
            $prepared = [];
            $totalQty = 0.0;
            $subCents = 0;
            $discountCents = 0;
            $hppCents = 0;
            $hppComplete = true;

            foreach ($items as $i => $rawItem) {
                $it = is_array($rawItem) ? $rawItem : [];
                $qty = (float) ($it['qty'] ?? 0);
                if (! is_finite($qty) || $qty <= 0 || floor($qty) !== $qty) {
                    throw new RuntimeException('Qty item #'.($i + 1).' harus lebih dari 0.');
                }

                $key = $this->stockKey($it);
                $mi = $this->find($master, $key);
                $pi = $this->find($produk, $key);
                if ($mi < 0) {
                    if ($pi < 0) {
                        throw new RuntimeException('SKU produk tidak ditemukan: '.($key['kode_sku'] ?: $key['id_produk']));
                    }
                    $product = $produk[$pi]['data'];
                    $master[] = ['id' => null, 'data' => [
                        'id_stok_item' => $product['id_stok_item'] ?? ('STK-'.strtoupper(substr(bin2hex(random_bytes(6)), 0, 10))),
                        'id_produk' => $product['id'] ?? '', 'kode_sku' => $product['kode'] ?? '', 'nama_produk' => $product['nama'] ?? '',
                        'stok_awal' => (float) ($product['stok'] ?? 0), 'masuk_jahit' => 0, 'masuk_vendor' => 0, 'keluar_penjualan' => 0,
                        'stok_akhir' => (float) ($product['stok'] ?? 0), 'status_stok' => 'Aman',
                    ]];
                    $mi = array_key_last($master);
                }

                $before = (float) ($master[$mi]['data']['stok_akhir'] ?? $master[$mi]['data']['stok'] ?? 0);
                if ($qty > $before) {
                    throw new RuntimeException('Stok tidak mencukupi untuk '.($it['nama_produk'] ?? $key['kode_sku']).'. Tersedia '.$before.', diminta '.$qty);
                }

                $unit = (float) ($it['harga_jual'] ?? 0);
                $discount = (float) ($it['diskon_item'] ?? 0);
                if (! is_finite($unit) || ! is_finite($discount) || $unit < 0 || $discount < 0 || $discount > $unit * $qty) {
                    throw new InvalidArgumentException('Harga atau diskon item tidak valid.');
                }

                $lineCents = (int) round($unit * 100) * (int) $qty;
                $subCents += $lineCents;
                $discountCents += (int) round($discount * 100);

                $unitHpp = $pi >= 0 ? ($produk[$pi]['data']['hpp_produk'] ?? null) : null;
                if ($unitHpp === null || ! is_numeric($unitHpp) || $unitHpp <= 0) {
                    $hppComplete = false;
                    $items[$i]['hpp_satuan'] = null;
                } else {
                    $items[$i]['hpp_satuan'] = (float) $unitHpp;
                    $hppCents += (int) round((float) $unitHpp * 100) * (int) $qty;
                }
                $items[$i]['subtotal'] = ($lineCents - (int) round($discount * 100)) / 100;

                $prepared[] = [$i, $mi, $pi, $before, $before - $qty];
                $master[$mi]['data']['stok_akhir'] = $before - $qty;
                $master[$mi]['data']['stok'] = $before - $qty;
                $dirtyMaster[$mi] = true;
                $totalQty += $qty;
            }

            foreach (['diskon_transaksi', 'ongkir', 'biaya_lain', 'nominal_dibayar'] as $field) {
                if (isset($p[$field]) && (! is_numeric($p[$field]) || ! is_finite((float) $p[$field]) || (float) $p[$field] < 0)) {
                    throw new InvalidArgumentException('Nominal penjualan tidak valid.');
                }
            }

            $goodsCents = $subCents - $discountCents - (int) round((float) ($p['diskon_transaksi'] ?? 0) * 100);
            if ($goodsCents < 0) {
                throw new InvalidArgumentException('Diskon transaksi melebihi nilai barang.');
            }
            $billCents = $goodsCents + (int) round((float) ($p['ongkir'] ?? 0) * 100) + (int) round((float) ($p['biaya_lain'] ?? 0) * 100);
            $tendered = (int) round((float) ($p['nominal_dibayar'] ?? 0) * 100);

            $p['items'] = $items;
            $p['subtotal'] = $subCents / 100;
            $p['diskon_item_total'] = $discountCents / 100;
            $p['total_tagihan'] = $billCents / 100;
            $p['total_qty'] = $totalQty;
            $p['uang_diterima'] = $tendered / 100;
            $p['nominal_dibayar'] = min($tendered, $billCents) / 100;
            $p['kembalian'] = max(0, $tendered - $billCents) / 100;
            $p['sisa_tagihan'] = max(0, $billCents - $tendered) / 100;
            $p['total_hpp'] = $hppComplete ? $hppCents / 100 : null;
            $p['laba_kotor'] = $hppComplete ? ($goodsCents - $hppCents) / 100 : null;
            $p['margin_persen'] = $hppComplete && $goodsCents > 0 ? round(($goodsCents - $hppCents) / $goodsCents * 100, 2) : null;
            $p['hpp_source'] = 'product_standard_snapshot';

            if ($p['nominal_dibayar'] > 0 && empty($p['id_rekening'])) {
                throw new InvalidArgumentException('Rekening penerimaan wajib dipilih.');
            }

            // Every check has passed: from here on only writes.
            $now = $this->now();
            $date = (string) ($p['tanggal'] ?? date('Y-m-d'));
            $movements = [];
            foreach ($prepared as [$i, $mi, $pi, $before, $after]) {
                $qty = (float) $items[$i]['qty'];
                $stock = &$master[$mi]['data'];
                $stock['keluar_penjualan'] = (float) ($stock['keluar_penjualan'] ?? 0) + $qty;
                $stock['last_updated'] = $now;
                $minimum = (float) ($stock['stok_minimum'] ?? 0);
                $stock['status_stok'] = $after <= 0 ? 'Habis' : ($after <= $minimum ? 'Menipis' : 'Aman');
                unset($stock);

                if ($pi >= 0) {
                    $produk[$pi]['data']['stok'] = $after;
                    $produk[$pi]['data']['updated_at'] = $now;
                    $dirtyProduk[$pi] = true;
                }

                $movements[] = [
                    'id_mutasi' => $this->newId('MUT'), 'tanggal' => $date, 'waktu' => date('H:i:s'), 'jenis_mutasi' => 'OUT_SALE',
                    'id_stok_item' => $master[$mi]['data']['id_stok_item'] ?? ($items[$i]['id_stok_item'] ?? ''),
                    'id_produk' => $master[$mi]['data']['id_produk'] ?? ($items[$i]['id_produk'] ?? ''),
                    'kode_sku' => $master[$mi]['data']['kode_sku'] ?? ($items[$i]['kode_sku'] ?? ''),
                    'qty_masuk' => 0, 'qty_keluar' => $qty, 'stok_sebelum' => $before, 'stok_setelah' => $after, 'saldo_berjalan' => $after,
                    'sumber_modul' => 'PENJUALAN', 'id_referensi' => $id, 'no_referensi' => $no, 'idempotency_key' => 'STOCK|SALE|'.$id.'|'.$i,
                    'operator' => $operator, 'created_at' => $now,
                ];
            }

            $record = array_merge($p, [
                'id_penjualan' => $id, 'no_penjualan' => $no, 'status_transaksi' => 'POSTED', 'total_qty' => $totalQty,
                'posted_at' => $now, 'posted_by' => $operator, 'operator' => $operator, 'updated_at' => $now,
                'created_at' => $p['created_at'] ?? $now, 'idempotency_key' => 'PENJUALAN|'.$id.'|POSTED', 'backup_status' => 'PENDING',
            ]);

            $this->saveCollection('master_stok', $master, array_keys($dirtyMaster));
            $this->saveCollection('produk', $produk, array_keys($dirtyProduk));
            foreach ($movements as $movement) {
                $this->collections->insert('mutasi_stok', $movement);
            }

            $existing = null;
            foreach ($sales as $row) {
                if ((string) ($row['data']['id_penjualan'] ?? '') === $id) {
                    $existing = $row;
                    break;
                }
            }
            if ($existing !== null) {
                $this->collections->update('penjualan', $existing['id'], array_merge($existing['data'], $record));
            } else {
                $this->collections->insert('penjualan', $record);
            }

            foreach ($this->collections->rows('penjualan_item', true) as $row) {
                if ((string) ($row['data']['id_penjualan'] ?? '') === $id) {
                    $this->collections->delete('penjualan_item', $row['id']);
                }
            }
            foreach ($items as $i => $item) {
                $this->collections->insert('penjualan_item', array_merge((array) $item, [
                    'id_item' => $item['id_item'] ?? $this->newId('SLI'), 'id_penjualan' => $id, 'no_penjualan' => $no,
                    'stok_sebelum' => $prepared[$i][3] ?? null, 'stok_setelah' => $prepared[$i][4] ?? null, 'created_at' => $now,
                ]));
            }

            $paid = (float) $record['nominal_dibayar'];
            if ($paid > 0) {
                $this->collections->insert('keuangan', [
                    'id' => $this->newId('K'), 'tanggal' => $date, 'tipe' => 'Masuk', 'nominal' => $paid, 'kategori' => 'Penjualan Hasil Produksi',
                    'modul' => 'Penjualan', 'id_rekening' => $record['id_rekening'] ?? '', 'id_referensi' => $id, 'no_referensi' => $no,
                    'idempotency_key' => 'FINANCE|SALE|'.$id, 'keterangan' => 'Penjualan '.$no, 'created_at' => $now, 'created_by' => $operator,
                ]);
            }

            $this->audit->record($user, 'Konveksi', 'POST_SALE', $id, $no, null, $record, 'Posting penjualan konveksi');

            return [
                'status' => 'success', 'code' => 'SALE_POSTED', 'message' => 'Penjualan berhasil diposting.', 'data' => $record,
                'stock' => ['posted' => true], 'finance' => ['posted' => $paid > 0],
            ];
        });
    }

    /**
     * Records a correction of a sale. A posted sale cannot change its items, amounts, date or account here:
     * that needs a void and a new posting so stock and cash stay consistent.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    public function correct(AuthenticatedUser $user, string $companyKey, array $p): array
    {
        $id = trim((string) ($p['id_penjualan'] ?? ''));
        if ($id === '') {
            throw new InvalidArgumentException('ID penjualan tidak valid.');
        }
        $operator = $this->operatorName($user);

        return $this->konveksi->connection()->transaction(function () use ($id, $p, $user, $operator): array {
            $found = null;
            foreach ($this->collections->rows('penjualan', true) as $row) {
                if ((string) ($row['data']['id_penjualan'] ?? '') === $id) {
                    $found = $row;
                    break;
                }
            }
            if ($found === null) {
                throw new DomainException('Penjualan tidak ditemukan.', 404);
            }

            $before = $found['data'];
            if (strtoupper((string) ($before['status_transaksi'] ?? '')) === 'POSTED') {
                foreach (self::POSTED_LOCKED_FIELDS as $field) {
                    if (array_key_exists($field, $p) && ($p[$field] ?? null) !== ($before[$field] ?? null)) {
                        throw new DomainException('Perubahan nilai, barang, tanggal atau rekening wajib melalui reversal dan posting ulang.', 409);
                    }
                }
            }

            $corrections = $this->collections->available('penjualan_koreksi') ? $this->collections->rows('penjualan_koreksi', true) : [];
            $revision = count(array_filter($corrections, fn (array $row) => (string) ($row['data']['id_penjualan'] ?? '') === $id)) + 1;
            $revisionNo = 'R'.str_pad((string) $revision, 2, '0', STR_PAD_LEFT);
            $now = $this->now();

            $after = array_merge($before, array_diff_key($p, array_flip(self::CORRECTION_PROTECTED_FIELDS)), [
                'updated_at' => $now, 'revision_no' => $revisionNo, 'last_correction_at' => $now, 'pdf_needs_refresh' => true,
            ]);
            $this->collections->update('penjualan', $found['id'], $after);

            $correction = [
                'id_koreksi' => $this->newId('CORR'), 'id_penjualan' => $id, 'no_penjualan' => $after['no_penjualan'] ?? '',
                'revision_no' => $revisionNo, 'tanggal_koreksi' => $now, 'alasan' => $p['alasan_koreksi'] ?? ($p['alasan'] ?? 'Koreksi Admin'),
                'before_json' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'after_json' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'operator' => $operator, 'created_at' => $now,
            ];
            if ($this->collections->available('penjualan_koreksi')) {
                $this->collections->insert('penjualan_koreksi', $correction);
            }

            $this->audit->record($user, 'Konveksi', 'CORRECT_SALE', $id, (string) ($after['no_penjualan'] ?? ''), $before, $after, 'Koreksi penjualan konveksi');

            return ['status' => 'success', 'message' => 'Koreksi penjualan tersimpan.', 'data' => ['revision_no' => $revisionNo, 'correction' => $correction]];
        });
    }

    /**
     * Voids a posted sale: stock goes back, a stock movement records it and the money received is reversed in the cash book.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    public function void(AuthenticatedUser $user, string $companyKey, array $p): array
    {
        $id = trim((string) ($p['id_penjualan'] ?? ''));
        if ($id === '') {
            throw new InvalidArgumentException('ID penjualan tidak valid.');
        }
        $operator = $this->operatorName($user);

        return $this->konveksi->connection()->transaction(function () use ($id, $p, $user, $operator): array {
            $found = null;
            foreach ($this->collections->rows('penjualan', true) as $row) {
                if ((string) ($row['data']['id_penjualan'] ?? '') === $id) {
                    $found = $row;
                    break;
                }
            }
            if ($found === null) {
                throw new DomainException('Penjualan tidak ditemukan.', 404);
            }

            $sale = $found['data'];
            $status = strtoupper((string) ($sale['status_transaksi'] ?? ''));
            if ($status === 'DRAFT') {
                throw new DomainException('Draft belum memiliki stok atau pembayaran untuk dibalik.');
            }
            if ($status === 'VOID') {
                return ['status' => 'success', 'code' => 'DUPLICATE_IGNORED', 'data' => $sale];
            }

            $master = $this->collections->rows('master_stok', true);
            $produk = $this->collections->rows('produk', true);
            $dirtyMaster = [];
            $dirtyProduk = [];
            $movements = [];
            $now = $this->now();
            $no = (string) ($sale['no_penjualan'] ?? '');

            foreach ($this->collections->rows('penjualan_item', true) as $row) {
                $item = $row['data'];
                if ((string) ($item['id_penjualan'] ?? '') !== $id) {
                    continue;
                }

                $key = $this->stockKey($item);
                $mi = $this->find($master, $key);
                $pi = $this->find($produk, $key);
                if ($mi < 0) {
                    throw new RuntimeException('Stok sumber pembalikan tidak ditemukan. Proses dibatalkan.');
                }

                $qty = (float) ($item['qty'] ?? 0);
                $before = (float) ($master[$mi]['data']['stok_akhir'] ?? 0);
                $after = $before + $qty;
                $master[$mi]['data']['stok_akhir'] = $after;
                $master[$mi]['data']['stok'] = $after;
                $master[$mi]['data']['keluar_penjualan'] = max(0, (float) ($master[$mi]['data']['keluar_penjualan'] ?? 0) - $qty);
                $dirtyMaster[$mi] = true;
                if ($pi >= 0) {
                    $produk[$pi]['data']['stok'] = $after;
                    $dirtyProduk[$pi] = true;
                }

                $movements[] = [
                    'id_mutasi' => $this->newId('MUT'), 'tanggal' => date('Y-m-d'), 'waktu' => date('H:i:s'), 'jenis_mutasi' => 'SALE_VOID_IN',
                    'id_stok_item' => $master[$mi]['data']['id_stok_item'] ?? '', 'id_produk' => $master[$mi]['data']['id_produk'] ?? '',
                    'kode_sku' => $master[$mi]['data']['kode_sku'] ?? '', 'qty_masuk' => $qty, 'qty_keluar' => 0, 'stok_sebelum' => $before,
                    'stok_setelah' => $after, 'saldo_berjalan' => $after, 'sumber_modul' => 'PENJUALAN_VOID', 'id_referensi' => $id,
                    'no_referensi' => $no, 'operator' => $operator, 'created_at' => $now,
                ];
            }

            $this->saveCollection('master_stok', $master, array_keys($dirtyMaster));
            $this->saveCollection('produk', $produk, array_keys($dirtyProduk));
            foreach ($movements as $movement) {
                $this->collections->insert('mutasi_stok', $movement);
            }

            $paid = (float) ($sale['nominal_dibayar'] ?? 0);
            if ($paid > 0) {
                $this->collections->insert('keuangan', [
                    'id' => $this->newId('K'), 'tanggal' => date('Y-m-d'), 'tipe' => 'Keluar', 'nominal' => $paid, 'kategori' => 'Reversal Penjualan',
                    'modul' => 'Penjualan', 'id_rekening' => $sale['id_rekening'] ?? '', 'id_referensi' => $id, 'no_referensi' => $no,
                    'idempotency_key' => 'FINANCE|VOID|'.$id, 'keterangan' => 'VOID '.$no, 'created_at' => $now, 'created_by' => $operator,
                ]);
            }

            $voided = array_merge($sale, [
                'status_transaksi' => 'VOID', 'void_reason' => $p['void_reason'] ?? ($p['alasan_void'] ?? ($p['alasan'] ?? 'Pembatalan')),
                'void_at' => $now, 'void_by' => $operator, 'updated_at' => $now,
            ]);
            $this->collections->update('penjualan', $found['id'], $voided);
            $this->audit->record($user, 'Konveksi', 'VOID_SALE', $id, $no, $sale, $voided, 'Pembatalan (VOID) penjualan konveksi');

            return ['status' => 'success', 'message' => 'Penjualan berhasil di-VOID.', 'data' => $voided];
        });
    }

    /** @param list<array{id: int|string|null, data: array<string, mixed>}> $rows */
    private function saveCollection(string $key, array $rows, array $dirty): void
    {
        foreach ($dirty as $index) {
            $row = $rows[$index];
            if ($row['id'] === null) {
                $this->collections->insert($key, $row['data']);
            } else {
                $this->collections->update($key, $row['id'], $row['data']);
            }
        }
    }

    /** @return array{id_stok_item: string, kode_sku: string, id_produk: string} */
    private function stockKey(array $item): array
    {
        return [
            'id_stok_item' => (string) ($item['id_stok_item'] ?? ''),
            'kode_sku' => (string) ($item['kode_sku'] ?? $item['sku_varian'] ?? $item['kode'] ?? ''),
            'id_produk' => (string) ($item['id_produk'] ?? $item['id'] ?? ''),
        ];
    }

    /**
     * Finds a stock/product row by the first identifier the item carries (stock id, then SKU, then product id).
     *
     * @param  list<array{id: int|string|null, data: array<string, mixed>}>  $rows
     * @param  array{id_stok_item: string, kode_sku: string, id_produk: string}  $key
     */
    private function find(array $rows, array $key): int
    {
        foreach (['id_stok_item' => ['id_stok_item'], 'kode_sku' => ['kode_sku', 'sku_varian', 'kode'], 'id_produk' => ['id_produk', 'id']] as $field => $aliases) {
            if ($key[$field] === '') {
                continue;
            }
            foreach ($rows as $index => $row) {
                foreach ($aliases as $alias) {
                    if (isset($row['data'][$alias]) && strcasecmp((string) $row['data'][$alias], $key[$field]) === 0) {
                        return $index;
                    }
                }
            }

            return -1;
        }

        return -1;
    }

    /** Next sale number of the day: PJL-YYYYMMDD-0001, 0002, ... based on the highest number already used today. */
    private function nextNumber(array $sales): string
    {
        $prefix = 'PJL-'.date('Ymd').'-';
        $max = 0;
        foreach ($sales as $row) {
            $number = (string) ($row['data']['no_penjualan'] ?? '');
            if (str_starts_with($number, $prefix)) {
                $max = max($max, (int) substr($number, -4));
            }
        }

        return $prefix.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    private function newId(string $prefix): string
    {
        return $prefix.'-'.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
    }

    private function now(): string
    {
        return now()->toIso8601String();
    }

    private function operatorName(AuthenticatedUser $user): string
    {
        return (string) ($user->getUsername() ?: $user->getAuthIdentifier() ?: 'system');
    }
}
