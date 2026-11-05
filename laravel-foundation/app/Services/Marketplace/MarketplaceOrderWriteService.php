<?php

namespace App\Services\Marketplace;

use App\Audit\AuditLogger;
use App\Auth\LegacyUser;
use DomainException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use LogicException;

final class MarketplaceOrderWriteService
{
    private const MANUAL_STATUSES = ['Pending', 'Terkirim', 'Selesai', 'Dibatalkan', 'Retur'];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditLogger $audit,
    ) {}

    public function recordManualOrder(LegacyUser $user, string $companyKey, array $d): string
    {
        $storeId = trim((string) ($d['store_id'] ?? ''));
        $orderId = trim((string) ($d['order_id'] ?? ''));
        if ($storeId === '') {
            throw new InvalidArgumentException('Toko aktif wajib dipilih.');
        }
        if ($orderId === '') {
            throw new InvalidArgumentException('Nomor pesanan wajib diisi.');
        }

        $connection = $this->database->connection();
        $store = $connection->table('marketplace_stores')
            ->where('id', $storeId)
            ->where('company_key', $companyKey)
            ->whereNull('archived_at')
            ->first();

        if ($store === null) {
            throw new DomainException('Toko marketplace tidak ditemukan dalam perusahaan aktif.', 403);
        }

        $orderDate = trim((string) ($d['order_date'] ?? '')) ?: now('Asia/Jakarta')->format('Y-m-d H:i:s');
        $completionDate = ! empty($d['completion_date']) ? (string) $d['completion_date'] : $orderDate;
        $buyer = trim((string) ($d['buyer_username'] ?? '')) ?: 'Customer Direct';
        $status = trim((string) ($d['order_status'] ?? '')) ?: 'Selesai';
        if (! in_array($status, self::MANUAL_STATUSES, true)) {
            throw new InvalidArgumentException('Status pesanan manual tidak valid.');
        }
        foreach (['gross_sales', 'platform_admin_fee', 'service_fee', 'payment_fee', 'total_cogs_hpp'] as $field) {
            if (isset($d[$field]) && $d[$field] !== '' && (! is_numeric($d[$field]) || ! is_finite((float) $d[$field]) || (float) $d[$field] < 0)) {
                throw new InvalidArgumentException('Nominal pesanan tidak valid.');
            }
        }
        if (isset($d['shipping_adjustment']) && $d['shipping_adjustment'] !== '' && (! is_numeric($d['shipping_adjustment']) || ! is_finite((float) $d['shipping_adjustment']))) {
            throw new InvalidArgumentException('Nominal pesanan tidak valid.');
        }
        $gross = (float) ($d['gross_sales'] ?? 0);
        $adminFee = (float) ($d['platform_admin_fee'] ?? 0);
        $serviceFee = (float) ($d['service_fee'] ?? 0);
        $paymentFee = (float) ($d['payment_fee'] ?? 0);
        $shipAdj = (float) ($d['shipping_adjustment'] ?? 0);
        $cogs = (float) ($d['total_cogs_hpp'] ?? 0);
        $net = $gross - $adminFee - $serviceFee - $paymentFee - $shipAdj;
        $margin = $net - $cogs;

        $sku = trim((string) ($d['sku'] ?? 'SKU-CUSTOM'));
        $itemName = trim((string) ($d['item_name'] ?? 'Produk Konveksi'));
        $qty = max(1, (int) ($d['qty'] ?? 1));
        $variantId = $d['product_variant_id'] ?? null;

        if ($connection->table('marketplace_orders')->where('id', $orderId)->exists()) {
            throw new InvalidArgumentException("Nomor pesanan {$orderId} sudah digunakan.");
        }

        $connection->transaction(function () use (
            $connection, $orderId, $storeId, $orderDate, $completionDate, $buyer, $status,
            $gross, $adminFee, $serviceFee, $paymentFee, $shipAdj, $net, $cogs, $margin,
            $sku, $itemName, $qty, $variantId, $user
        ): void {
            $connection->table('marketplace_orders')->insert([
                'id' => $orderId,
                'store_id' => $storeId,
                'order_date' => $orderDate,
                'completion_date' => $completionDate,
                'buyer_username' => $buyer,
                'order_status' => $status,
                'gross_sales' => $gross,
                'seller_discount' => 0,
                'platform_admin_fee' => $adminFee,
                'service_fee' => $serviceFee,
                'payment_fee' => $paymentFee,
                'shipping_adjustment' => $shipAdj,
                'other_deductions' => 0,
                'operational_expense' => 0,
                'adjustment_amount' => 0,
                'net_settlement_amount' => $net,
                'total_cogs_hpp' => $cogs,
                'net_margin_profit' => $margin,
                'escrow_status' => 'Tertahan',
                'source_kind' => 'manual',
                'created_at' => now(),
            ]);

            $connection->table('marketplace_order_items')->insert([
                'order_id' => $orderId,
                'product_variant_id' => $variantId,
                'sku' => $sku,
                'item_name' => $itemName,
                'qty' => $qty,
                'selling_price' => $gross,
                'unit_cogs_hpp' => $cogs,
                'subtotal_price' => $gross,
                'subtotal_cogs' => $cogs,
            ]);

            $this->audit->record(
                $user,
                'Marketplace',
                'ADD_MANUAL_ORDER',
                $orderId,
                $storeId,
                null,
                [
                    'store_id' => $storeId,
                    'order_id' => $orderId,
                    'gross_sales' => $gross,
                    'buyer' => $buyer,
                    'status' => $status,
                ],
                "Tambah pesanan manual: {$orderId}",
            );
        });

        return $orderId;
    }

    public function updateManualOrder(LegacyUser $user, string $companyKey, string $orderId, string $storeId, array $data): array
    {
        $connection = $this->database->connection();
        $order = $connection->table('marketplace_orders')
            ->where('id', $orderId)
            ->where('store_id', $storeId)
            ->first();

        if (! $order) {
            throw new DomainException('Pesanan tidak ditemukan dalam toko aktif.', 404);
        }
        if (($order->source_kind ?? 'api') !== 'manual') {
            throw new LogicException('Pesanan dari API tidak dapat diedit sebagai transaksi manual. Ubah melalui penyesuaian internal yang terpisah.');
        }
        if (($order->internal_state ?? 'active') === 'archived') {
            throw new LogicException('Pesanan yang sudah diarsipkan tidak dapat diedit.');
        }

        $status = trim((string) ($data['order_status'] ?? $order->order_status));
        if (! in_array($status, self::MANUAL_STATUSES, true)) {
            throw new InvalidArgumentException('Status pesanan manual tidak valid.');
        }

        $buyer = trim((string) ($data['buyer_username'] ?? $order->buyer_username ?? 'Customer Direct'));
        $hpp = max(0, (float) ($data['total_cogs_hpp'] ?? $order->total_cogs_hpp));
        $net = (float) $order->gross_sales - (float) $order->seller_discount - (float) $order->platform_admin_fee - (float) $order->service_fee - (float) $order->payment_fee - (float) $order->shipping_adjustment - (float) $order->other_deductions;

        $connection->table('marketplace_orders')
            ->where('id', $orderId)
            ->where('store_id', $storeId)
            ->where('source_kind', 'manual')
            ->update([
                'buyer_username' => $buyer,
                'order_status' => $status,
                'total_cogs_hpp' => $hpp,
                'net_margin_profit' => $net - $hpp,
                'completion_date' => $status === 'Selesai' ? ($order->completion_date ?: now()) : $order->completion_date,
            ]);

        $this->audit->record(
            $user,
            'Marketplace',
            'UPDATE_MANUAL_ORDER',
            $orderId,
            $storeId,
            ['buyer' => (string) $order->buyer_username, 'status' => (string) $order->order_status],
            ['buyer' => $buyer, 'status' => $status, 'total_cogs_hpp' => $hpp],
            "Update pesanan manual: {$orderId}",
        );

        return ['success' => true, 'order_id' => $orderId, 'message' => 'Pesanan manual berhasil diperbarui.'];
    }

    public function archiveOrder(LegacyUser $user, string $companyKey, string $orderId, string $storeId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Alasan arsip wajib diisi.');
        }

        $connection = $this->database->connection();
        $order = $connection->table('marketplace_orders')
            ->where('id', $orderId)
            ->where('store_id', $storeId)
            ->first();

        if (! $order) {
            throw new DomainException('Pesanan tidak ditemukan dalam toko aktif.', 404);
        }
        if (($order->internal_state ?? 'active') === 'archived') {
            return ['success' => true, 'order_id' => $orderId, 'message' => 'Pesanan sudah diarsipkan sebelumnya.'];
        }

        $by = (string) ($user->getUsername() ?: $user->getAuthIdentifier() ?: 'system');

        $connection->table('marketplace_orders')
            ->where('id', $orderId)
            ->where('store_id', $storeId)
            ->update([
                'internal_state' => 'archived',
                'archived_at' => now(),
                'archived_by' => $by,
                'archive_reason' => $reason,
            ]);

        $this->audit->record(
            $user,
            'Marketplace',
            'ARCHIVE_ORDER',
            $orderId,
            $storeId,
            null,
            ['reason' => $reason, 'archived_by' => $by],
            "Pesanan {$orderId} diarsipkan: {$reason}",
        );

        return [
            'success' => true,
            'order_id' => $orderId,
            'source_kind' => $order->source_kind ?? 'api',
            'message' => 'Pesanan diarsipkan dari laporan internal. Data platform tidak dihapus.',
        ];
    }

    public function getJournalEntries(string $companyKey, string $storeId, ?string $dateFrom = null, ?string $dateTo = null, ?string $refType = null): array
    {
        $connection = $this->database->connection();
        $inCompany = $storeId !== '' && $connection->table('marketplace_stores')->where('id', $storeId)->where('company_key', $companyKey)->exists();
        if (! $inCompany) {
            throw new DomainException('Toko tidak ditemukan dalam perusahaan aktif.', 403);
        }

        $query = $connection->table('marketplace_journal_entries')->where('store_id', $storeId);
        if ($dateFrom) {
            $query->where('entry_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('entry_date', '<=', $dateTo);
        }
        if ($refType && $refType !== 'Semua Tipe') {
            $query->where('ref_type', $refType);
        }

        $rows = $query->orderByDesc('entry_date')->orderByDesc('journal_no')->orderByDesc('debit')->limit(200)->get()->map(fn ($r) => (array) $r)->all();

        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($rows as $r) {
            $totalDebit += (float) ($r['debit'] ?? 0);
            $totalCredit += (float) ($r['credit'] ?? 0);
        }

        $isBalanced = abs($totalDebit - $totalCredit) < 0.01;

        return [
            'entries' => $rows,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balanced' => $isBalanced,
            'count' => count($rows),
            'summary' => [
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'is_balanced' => $isBalanced,
                'count' => count($rows),
            ],
        ];
    }
}
