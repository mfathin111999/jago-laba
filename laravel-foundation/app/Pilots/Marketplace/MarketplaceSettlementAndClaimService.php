<?php

namespace App\Pilots\Marketplace;

use App\Audit\AuditLogger;
use App\Auth\LegacyUser;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;

final class MarketplaceSettlementAndClaimService
{
    /** The values of marketplace_return_claims.claim_status. */
    private const CLAIM_STATUSES = ['MENUNGGU_FISIK', 'KLAIM_DIAJUKAN', 'DISETUJUI_GANTI_RUGI', 'DITOLAK', 'FISIK_DITERIMA'];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditLogger $audit,
    ) {}

    public function updateReturnClaimStatus(
        LegacyUser $user,
        string $companyKey,
        int $claimId,
        string $storeId,
        string $status,
        float $reimbursementAmount = 0.0,
        ?string $notes = null
    ): array {
        // The database enum would reject anything else with a 500; refuse it up front with a readable 422.
        if (! in_array($status, self::CLAIM_STATUSES, true)) {
            throw new InvalidArgumentException('Status klaim tidak dikenal.');
        }

        $connection = $this->database->connection();

        return $connection->transaction(function () use (
            $connection, $claimId, $storeId, $status, $reimbursementAmount, $notes, $user
        ): array {
            $claim = $connection->table('marketplace_return_claims as rc')
                ->join('marketplace_stores as s', 'rc.store_id', '=', 's.id')
                ->select(['rc.*', 's.default_account_id'])
                ->where('rc.id', $claimId)
                ->where('rc.store_id', $storeId)
                ->lockForUpdate()
                ->first();

            if (! $claim) {
                throw new InvalidArgumentException('Data klaim retur tidak ditemukan.');
            }

            if ($claim->claim_status === 'DISETUJUI_GANTI_RUGI'
                && ($status !== 'DISETUJUI_GANTI_RUGI' || (float) $claim->reimbursement_amount !== $reimbursementAmount)) {
                throw new LogicException('Klaim yang sudah dibukukan memerlukan jurnal penyesuaian berjejak.');
            }

            $resolvedAt = in_array($status, ['DISETUJUI_GANTI_RUGI', 'DITOLAK', 'FISIK_DITERIMA'], true) ? now() : $claim->resolved_at;

            $connection->table('marketplace_return_claims')
                ->where('id', $claimId)
                ->update([
                    'claim_status' => $status,
                    'reimbursement_amount' => $reimbursementAmount,
                    'evidence_notes' => $notes ?: $claim->evidence_notes,
                    'resolved_at' => $resolvedAt,
                    'updated_at' => now(),
                ]);

            $connection->table('marketplace_orders')
                ->where('id', $claim->order_id)
                ->update(['return_claim_status' => $status]);

            if ($status === 'DISETUJUI_GANTI_RUGI' && $claim->claim_status !== 'DISETUJUI_GANTI_RUGI' && $reimbursementAmount > 0) {
                $jno = 'JRN-CLM-'.date('Ymd').'-'.$claimId;
                $today = now('Asia/Jakarta')->format('Y-m-d');
                $now = now();

                if (Schema::hasTable('marketplace_journal_entries')) {
                    $connection->table('marketplace_journal_entries')->insert([
                        [
                            'journal_no' => $jno,
                            'entry_date' => $today,
                            'ref_type' => 'RETURN_LOSS',
                            'ref_id' => $claim->order_id,
                            'store_id' => $claim->store_id,
                            'account_code' => '1101',
                            'account_name' => 'Kas/Bank Penerimaan Ganti Rugi',
                            'debit' => $reimbursementAmount,
                            'credit' => 0.00,
                            'memo' => "Kompensasi Paket Hilang #{$claim->order_id}",
                            'created_at' => $now,
                        ],
                        [
                            'journal_no' => $jno,
                            'entry_date' => $today,
                            'ref_type' => 'RETURN_LOSS',
                            'ref_id' => $claim->order_id,
                            'store_id' => $claim->store_id,
                            'account_code' => '4201',
                            'account_name' => 'Pendapatan Lain-lain (Klaim Logistik)',
                            'debit' => 0.00,
                            'credit' => $reimbursementAmount,
                            'memo' => "Penggantian Ekspedisi #{$claim->order_id}",
                            'created_at' => $now,
                        ],
                    ]);
                }
            }

            $this->audit->record(
                $user,
                'Marketplace',
                'UPDATE_RETURN_CLAIM',
                (string) $claimId,
                (string) $claim->order_id,
                ['status' => (string) $claim->claim_status],
                ['status' => $status, 'reimbursement_amount' => $reimbursementAmount],
                "Status klaim retur diperbarui menjadi {$status}",
            );

            return [
                'success' => true,
                'claim_id' => $claimId,
                'new_status' => $status,
                'reimbursement_amount' => $reimbursementAmount,
                'message' => "Status klaim retur berhasil diperbarui menjadi {$status}.",
            ];
        });
    }

    public function importProductAdReport(LegacyUser $user, string $companyKey, string $storeId, string $path): int
    {
        $connection = $this->database->connection();
        $store = $connection->table('marketplace_stores')
            ->where('id', $storeId)
            ->where('company_key', $companyKey)
            ->first();

        if (! $store || ! in_array($store->platform, ['Shopee', 'TikTok Shop'], true)) {
            throw new InvalidArgumentException('Platform iklan tidak didukung.');
        }

        $handle = fopen($path, 'rb');
        if (! $handle) {
            throw new InvalidArgumentException('Laporan tidak dapat dibaca.');
        }

        $rows = [];
        $seen = [];
        try {
            $headers = fgetcsv($handle, 0, ',', '"', '');
            if (! $headers) {
                throw new InvalidArgumentException('Laporan kosong.');
            }
            $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
            $required = ['marketplace_item_id', 'sku', 'product_name', 'campaign_name', 'period_start', 'period_end', 'ad_spend', 'gross_revenue', 'orders_count', 'qty_sold', 'unit_cogs_hpp', 'platform_fee', 'affiliate_fee'];
            if ($headers !== $required) {
                throw new InvalidArgumentException('Gunakan urutan kolom pada template CSV laporan produk.');
            }

            while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                $line = count($rows) + 2;
                if (count($values) !== count($headers)) {
                    throw new InvalidArgumentException("Jumlah kolom baris {$line} tidak sesuai template.");
                }
                $row = array_combine($headers, array_map('trim', $values));
                $identity = json_encode([$row['marketplace_item_id'], $row['sku'], $row['campaign_name'], $row['period_start'], $row['period_end']]);
                if (isset($seen[$identity])) {
                    throw new InvalidArgumentException("Laporan duplikat pada baris {$line}.");
                }
                $seen[$identity] = true;
                $rows[] = $row;
                if (count($rows) > 5000) {
                    throw new InvalidArgumentException('Maksimal 5.000 baris per impor.');
                }
            }
        } finally {
            fclose($handle);
        }

        if ($rows === []) {
            throw new InvalidArgumentException('Laporan tidak berisi produk.');
        }

        return $connection->transaction(function () use ($connection, $storeId, $store, $rows, $user): int {
            foreach ($rows as $row) {
                $existing = $connection->table('marketplace_product_ads')
                    ->where('store_id', $storeId)
                    ->where('marketplace_item_id', $row['marketplace_item_id'])
                    ->where('sku', $row['sku'])
                    ->where('ad_type', 'Produk')
                    ->where('period_start', '<=', $row['period_end'])
                    ->where('period_end', '>=', $row['period_start'])
                    ->first();

                $columns = array_keys($row);
                $values = array_values($row);

                if ($existing) {
                    $updateMap = array_combine($columns, $values);
                    $updateMap['last_synced_at'] = now();
                    $updateMap['is_bocor'] = 0;
                    $updateMap['bocor_reason'] = null;
                    $connection->table('marketplace_product_ads')->where('id', $existing->id)->update($updateMap);
                } else {
                    $insertMap = array_combine($columns, $values);
                    $insertMap['store_id'] = $storeId;
                    $insertMap['platform'] = $store->platform;
                    $insertMap['ad_type'] = 'Produk';
                    $insertMap['image_url'] = '';
                    $insertMap['last_synced_at'] = now();
                    $connection->table('marketplace_product_ads')->insert($insertMap);
                }
            }

            $count = count($rows);
            $this->audit->record(
                $user,
                'Marketplace',
                'IMPORT_AD_REPORT',
                $storeId,
                $storeId,
                null,
                ['count' => $count],
                "Impor {$count} baris laporan iklan produk.",
            );

            return $count;
        });
    }

    public function importSettlementCSV(LegacyUser $user, string $companyKey, string $storeId, string $tmpPath, string $origName): array
    {
        $connection = $this->database->connection();
        $store = $connection->table('marketplace_stores')
            ->where('id', $storeId)
            ->where('company_key', $companyKey)
            ->first();

        if (! $store) {
            throw new DomainException('Toko tidak ditemukan dalam perusahaan aktif.', 403);
        }

        // Disabled on purpose, as in the legacy service: the old import guessed gross sales, HPP and fees and
        // updated orders by id alone. It may return only with a reviewed payout/account reconciliation design.
        throw new LogicException('Impor settlement menunggu rekening dan rekonsiliasi Marketplace terpisah. Tidak ada data yang diubah.');
    }
}
