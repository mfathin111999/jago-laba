<?php

namespace App\Services\Marketplace;

use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Read-only adapter for the legacy Marketplace order/list/detail contract.
 * It never refreshes a provider, changes order rows, or creates queue work.
 */
final class OrderReadService
{
    public const NO_INTEGRATED_STORE = 4101;

    public const STORE_SELECTION_REQUIRED = 4102;

    public const STORE_FORBIDDEN = 4103;

    public const EXPORT_LIMIT = 5000;

    /** @var array<string, string> */
    private const TABS = [
        'all' => 'Semua',
        'new' => 'Pesanan Baru',
        'pickup' => 'Menunggu Pickup',
        'shipped' => 'Dikirim',
        'cancelled' => 'Dibatalkan',
        'returns' => 'Pengembalian Barang',
    ];

    public function __construct(private readonly DatabaseManager $database) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(string $companyKey, ?string $requestedStore, array $filters): array
    {
        $stores = $this->stores($companyKey);
        $integrated = array_values(array_filter($stores, static fn (array $store): bool => (string) ($store['oauth_connection_id'] ?? '') !== ''
            && (string) ($store['oauth_authorized_at'] ?? '') !== '',
        ));

        if ($integrated === []) {
            throw new DomainException('Belum ada store Marketplace yang terhubung.', self::NO_INTEGRATED_STORE);
        }

        $activeStore = $this->resolveStore($integrated, $requestedStore);
        $orders = $this->listing($companyKey, $activeStore, $filters);

        return [
            'read_only' => true,
            'workspace' => 'marketplace',
            'scope' => ['company_key' => $companyKey, 'store_id' => $activeStore['id']],
            'stores' => $stores,
            'active_store' => $activeStore,
            'filters' => $filters + ['period' => 'month', 'order_tab' => 'all', 'per_page' => 50],
            'orders' => $orders,
            'query' => [
                'tab' => 'pesanan',
                'store' => $activeStore['id'],
                'filters' => $filters,
                'read_only' => true,
                'provider_call' => false,
                'tables' => ['marketplace_stores', 'marketplace_orders', 'marketplace_order_items', 'marketplace_sync_records'],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function stores(string $companyKey): array
    {
        $query = $this->connection()->table('marketplace_stores as s')
            ->select([
                's.id', 's.company_key', 's.platform', 's.store_name', 's.store_identifier',
                's.authorization_status', 's.archived_at',
            ])
            ->where('s.company_key', $companyKey)
            ->whereNull('s.archived_at')
            ->orderBy('s.platform')
            ->orderBy('s.store_name');

        if ($this->hasTable('marketplace_oauth_connections')) {
            $query->leftJoin('marketplace_oauth_connections as c', function ($join): void {
                $join->on('c.store_id', '=', 's.id')->on('c.company_key', '=', 's.company_key');
            })->addSelect([
                'c.id as oauth_connection_id',
                'c.authorized_at as oauth_authorized_at',
                'c.status as oauth_status',
                'c.verified_at as oauth_verified_at',
                'c.last_successful_sync_at',
            ]);
        }

        return $query->get()->map(static function ($row): array {
            $store = (array) $row;
            $connected = (string) ($store['oauth_connection_id'] ?? '') !== ''
                && (string) ($store['oauth_authorized_at'] ?? '') !== '';
            $verified = $connected
                && in_array((string) ($store['oauth_status'] ?? ''), ['verified', 'authorized'], true)
                && (string) ($store['oauth_verified_at'] ?? '') !== '';
            $store['authorization_label'] = $verified ? 'Terhubung & terverifikasi' : ($connected ? 'Terhubung' : 'Belum terhubung');
            $store['is_connection_verified'] = $verified;

            return $store;
        })->all();
    }

    /**
     * @param  array<string, mixed>  $store
     * @return array<string, mixed>
     */
    public function listing(string $companyKey, array $store, array $filters): array
    {
        $storeId = (string) ($store['id'] ?? '');
        $platform = (string) ($store['platform'] ?? '');
        $period = MarketplacePilotReadService::period($filters);
        $tab = (string) ($filters['order_tab'] ?? 'all');
        if ($tab === 'complaint') {
            $tab = 'returns';
        }
        if (! array_key_exists($tab, self::TABS)) {
            $tab = 'all';
        }

        $returnDate = in_array((string) ($filters['return_date'] ?? 'created'), ['created', 'updated', 'order'], true)
            ? (string) ($filters['return_date'] ?? 'created')
            : 'created';
        $returnRows = $this->returnRows($storeId);
        $returnMap = $this->normalizeReturns($returnRows, $platform);

        $query = $this->connection()->table('marketplace_orders as o')
            ->select(['o.*']);
        if ($this->hasTable('marketplace_sync_records')) {
            $query->leftJoin('marketplace_sync_records as r', function ($join): void {
                $join->on('r.store_id', '=', 'o.store_id')
                    ->where('r.resource_type', 'order')
                    ->on('r.external_id', '=', 'o.id');
            })->addSelect(['r.payload_json', 'r.source_updated_at']);
        }

        $query->where('o.store_id', $storeId)
            ->where('o.source_kind', 'api')
            ->where(function ($builder): void {
                $builder->whereNull('o.internal_state')->orWhere('o.internal_state', 'active');
            });

        if ($tab !== 'returns' || $returnDate === 'order') {
            $query->where('o.order_date', '>=', $period['start'])
                ->where('o.order_date', '<', $period['exclusive_end']);
        }

        $rawRows = $query->orderByDesc('o.order_date')->orderByDesc('o.id')->get()
            ->map(static fn ($row): array => (array) $row)->all();

        $prepared = [];
        foreach ($rawRows as $raw) {
            $row = $this->present($raw, $platform);
            $row['return_cases'] = $returnMap[(string) $row['id']] ?? [];

            if ($tab === 'returns' && $returnDate !== 'order' && ! $this->returnMatchesPeriod($row['return_cases'], $period, $returnDate)) {
                continue;
            }
            if (! $this->matchesFilters($row, $filters, $tab)) {
                continue;
            }
            $prepared[] = $row;
        }

        $counts = array_fill_keys(array_keys(self::TABS), 0);
        foreach ($prepared as $row) {
            $counts['all']++;
            $group = (string) ($row['group'] ?? 'all');
            if (array_key_exists($group, $counts) && $group !== 'all') {
                $counts[$group]++;
            }
            if (($row['return_cases'] ?? []) !== []) {
                $counts['returns']++;
            }
        }

        $selected = array_values(array_filter($prepared, static function (array $row) use ($tab): bool {
            return $tab === 'all' || ($tab === 'returns' && ($row['return_cases'] ?? []) !== []) || ($row['group'] ?? 'all') === $tab;
        }));
        $export = ! empty($filters['export']);
        if ($export && count($selected) > self::EXPORT_LIMIT) {
            throw new InvalidArgumentException('Ekspor maksimal 5.000 pesanan. Persempit periode atau filter.');
        }
        $perPage = in_array((int) ($filters['per_page'] ?? 50), [25, 50, 100], true) ? (int) $filters['per_page'] : 50;
        $pages = max(1, (int) ceil(count($selected) / $perPage));
        $page = min($pages, max(1, (int) ($filters['page'] ?? 1)));

        return [
            'store' => $store,
            'period' => $period,
            'counts' => $counts,
            'tab' => $tab,
            'rows' => $export ? $selected : array_values(array_slice($selected, ($page - 1) * $perPage, $perPage)),
            'total' => count($selected),
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            'return_date' => $returnDate,
            'return_coverage' => $this->returnCoverage($storeId),
            'complaint_coverage' => false,
            'label_supported' => $platform === 'TikTok Shop',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detail(string $companyKey, string $storeId, string $orderId): ?array
    {
        $store = null;
        foreach ($this->stores($companyKey) as $candidate) {
            if ((string) $candidate['id'] === $storeId) {
                $store = $candidate;
                break;
            }
        }
        if ($store === null) {
            throw new DomainException('Store Marketplace tidak diizinkan.', self::STORE_FORBIDDEN);
        }

        $order = (array) $this->connection()->table('marketplace_orders as o')
            ->join('marketplace_stores as s', 's.id', '=', 'o.store_id')
            ->where('o.id', $orderId)
            ->where('o.store_id', $storeId)
            ->where('s.company_key', $companyKey)
            ->select(['o.*', 's.store_name', 's.platform'])
            ->first();
        if ($order === []) {
            return null;
        }

        if ($this->hasTable('marketplace_order_items')) {
            $order['items'] = $this->connection()->table('marketplace_order_items')
                ->where('order_id', $orderId)->get()->map(static fn ($item): array => (array) $item)->all();
        }

        $presentation = $this->buildPresentation($order);
        $returnMap = $this->normalizeReturns($this->returnRows($storeId), (string) ($store['platform'] ?? ''));
        $presentation['return_cases'] = $returnMap[$orderId] ?? [];

        return [
            'read_only' => true,
            'scope' => ['company_key' => $companyKey, 'store_id' => $storeId],
            'order' => $order,
            'presentation' => $presentation,
        ];
    }

    public static function group(string $status, string $platform): string
    {
        if (in_array($status, ['CANCELLED', 'Dibatalkan', 'Batal'], true)) {
            return 'cancelled';
        }
        if (in_array($status, ['IN_TRANSIT', 'SHIPPED', 'TO_CONFIRM_RECEIVE', 'PARTIALLY_SHIPPING', 'Terkirim'], true)) {
            return 'shipped';
        }
        if ($status === 'AWAITING_COLLECTION' || ($platform === 'Shopee' && $status === 'PROCESSED')) {
            return 'pickup';
        }
        if (in_array($status, ['AWAITING_SHIPMENT', 'READY_TO_SHIP'], true) || ($platform === 'TikTok Shop' && $status === 'PROCESSED')) {
            return 'new';
        }

        return 'all';
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function present(array $row, string $platform): array
    {
        $snapshot = $this->json($row['transaction_detail_json'] ?? null);
        $raw = $this->json($row['payload_json'] ?? null);
        $sourceStatus = trim((string) ($snapshot['source_status'] ?? $row['order_status'] ?? ''));
        $presentation = $this->buildPresentation($row);
        $items = $presentation['items'];
        $quantity = $items === [] ? null : array_sum(array_map(static fn (array $item): float => (float) ($item['quantity'] ?? 0), $items));
        $recipient = is_array($raw['recipient_address'] ?? null) ? $raw['recipient_address'] : [];
        $packages = $this->packages($raw);
        $group = self::group($sourceStatus, $platform);
        $cancelRequested = ! in_array($sourceStatus, ['CANCELLED', 'Dibatalkan'], true)
            && (($snapshot['is_buyer_request_cancel'] ?? false) === true || in_array($sourceStatus, ['IN_CANCEL', 'CANCEL_REQUESTED'], true));

        return [
            'id' => (string) ($row['id'] ?? ''),
            'date' => $row['order_date'] ?? null,
            'source_status' => $sourceStatus,
            'status' => $cancelRequested ? 'Pengajuan Pembatalan' : self::statusLabel($sourceStatus),
            'group' => $group,
            'cancel_requested' => $cancelRequested,
            'complaint_active' => ($snapshot['complaint_active'] ?? null) === true,
            'has_return' => ($snapshot['has_return'] ?? null) === true,
            'has_refund' => ($snapshot['has_refund'] ?? null) === true,
            'items' => $items,
            'quantity' => $quantity,
            'amount' => $this->numericOrNull($raw['payment']['total_amount'] ?? $raw['total_amount'] ?? $snapshot['order_paid_amount'] ?? null),
            'payment_method' => (string) ($snapshot['payment_method'] ?? $row['payment_method'] ?? ''),
            'is_cod' => $snapshot['is_cod'] ?? null,
            'recipient' => (string) ($recipient['name'] ?? ''),
            'address' => (string) ($recipient['full_address'] ?? ''),
            'phone' => (string) ($recipient['phone_number'] ?? $recipient['phone'] ?? ''),
            'courier' => (string) ($raw['shipping_provider'] ?? $raw['shipping_carrier'] ?? $snapshot['shipping_provider'] ?? ''),
            'tracking' => (string) ($raw['tracking_number'] ?? $snapshot['tracking_number'] ?? ''),
            'packages' => $packages,
            'timeline' => $this->timeline($raw),
            'updated' => $snapshot['source_updated_at'] ?? $row['source_updated_at'] ?? null,
            'cancel_reason' => (string) ($snapshot['cancel_reason'] ?? $raw['cancel_reason'] ?? ''),
            'presentation' => $presentation,
        ];
    }

    private static function statusLabel(string $source): string
    {
        return [
            'UNPAID' => 'Belum Dibayar', 'ON_HOLD' => 'Menunggu Konfirmasi',
            'AWAITING_SHIPMENT' => 'Perlu Dikirim', 'READY_TO_SHIP' => 'Perlu Dikirim', 'PROCESSED' => 'Perlu Dikirim',
            'AWAITING_COLLECTION' => 'Menunggu Pickup', 'PARTIALLY_SHIPPING' => 'Dikirim Sebagian',
            'IN_TRANSIT' => 'Sedang Dikirim', 'SHIPPED' => 'Sedang Dikirim', 'TO_CONFIRM_RECEIVE' => 'Sedang Dikirim',
            'DELIVERED' => 'Sudah Diterima', 'COMPLETED' => 'Selesai', 'CANCELLED' => 'Batal', 'Dibatalkan' => 'Batal',
            'IN_CANCEL' => 'Pengajuan Pembatalan', 'CANCEL_REQUESTED' => 'Pengajuan Pembatalan',
        ][$source] ?? ($source !== '' ? $source : 'Belum Dipetakan');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function buildPresentation(array $row): array
    {
        $snapshot = $this->json($row['transaction_detail_json'] ?? null);
        $gross = $this->numericOrNull($snapshot['gross_amount'] ?? $row['gross_sales'] ?? 0) ?? 0.0;
        $labels = [
            'admin_fee' => ['Biaya Admin', 'platform_admin_fee', 1], 'service_fee' => ['Biaya Layanan', 'service_fee', 1],
            'actual_shipping' => ['Ongkir Aktual', null, 1], 'withholding_tax' => ['Pajak Pemotongan', null, 1],
            'seller_voucher' => ['Voucher dari Penjual', 'seller_discount', 1], 'shipping_subsidy' => ['Subsidi Ongkir Shopee', null, -1],
            'processing_fee' => ['Biaya Pemrosesan Pesanan', 'payment_fee', 1], 'shipping_adjustment' => ['Penyesuaian Ongkir', 'shipping_adjustment', 1],
            'other_deductions' => ['Potongan Lain', 'other_deductions', 1], 'affiliate_commission' => ['Komisi Affiliate', 'affiliate_commission', 1],
            'live_commission' => ['Komisi Live', 'live_commission', 1], 'shipping_discount' => ['Diskon Ongkir', null, -1],
            'buyer_shipping' => ['Ongkir Dibayar Pembeli', null, -1], 'dynamic_commission' => ['Komisi Dinamis', null, 1],
            'platform_commission' => ['Komisi Platform', null, 1], 'ams_admin_fee' => ['Biaya Admin AMS', null, 1],
            'cashback_fee' => ['Biaya Cashback', null, 1], 'mall_fee' => ['Biaya Mall', null, 1], 'logistics_fee' => ['Biaya Logistik', null, 1],
            'infrastructure_fee' => ['Biaya Infrastruktur', null, 1], 'ads_affiliate_commission' => ['Komisi Ads Afiliasi', null, 1],
            'shipping_savings_fee' => ['Biaya Program Hemat Biaya Kirim', null, 1], 'seller_transaction_fee' => ['Biaya Transaksi Penjual', null, 1],
            'shipping_protection' => ['Premi Proteksi Pengiriman', null, 1], 'buyer_refund' => ['Pengembalian Dana ke Pembeli', null, 1],
            'paylater_fee' => ['Biaya PayLater', null, 1],
        ];
        $components = [];
        foreach ($labels as $key => [$label, $legacy, $sign]) {
            $value = $snapshot['components'][$key] ?? ($legacy ? ($row[$legacy] ?? null) : null);
            if ($value === null || ! is_numeric($value)) {
                continue;
            }
            $amount = (float) $value;
            $components[] = ['key' => $key, 'label' => $label, 'amount' => $amount, 'effect' => $sign, 'percent' => $gross > 0 ? round($amount / $gross * 100, 1) : null];
        }
        if (! empty($snapshot['components_signed'])) {
            $components = [];
            foreach (($snapshot['components'] ?? []) as $key => $amount) {
                if (is_numeric($amount)) {
                    $components[] = ['key' => $key, 'label' => (string) ($snapshot['component_labels'][$key] ?? $labels[$key][0] ?? $key), 'amount' => abs((float) $amount), 'effect' => (float) $amount < 0 ? -1 : 1, 'percent' => $gross > 0 ? round(abs((float) $amount) / $gross * 100, 1) : null];
                }
            }
        }
        $componentTotal = array_sum(array_map(static fn (array $item): float => $item['amount'] * $item['effect'], $components));
        $net = array_key_exists('net_income', $snapshot) ? $this->numericOrNull($snapshot['net_income']) : $this->numericOrNull($row['net_settlement_amount'] ?? null);
        $fees = array_key_exists('total_fees', $snapshot) ? $this->numericOrNull($snapshot['total_fees']) : ($components === [] ? null : $componentTotal);
        $hpp = array_key_exists('total_cogs', $snapshot) ? $this->numericOrNull($snapshot['total_cogs']) : $this->numericOrNull($row['total_cogs_hpp'] ?? null);
        $items = $snapshot['items'] ?? $row['items'] ?? [];
        $items = is_array($items) ? array_map(function (mixed $item): array {
            $item = is_object($item) ? (array) $item : (is_array($item) ? $item : []);
            $qty = $this->numericOrNull($item['quantity'] ?? $item['qty'] ?? 0) ?? 0.0;
            $returned = array_key_exists('returned_quantity', $item) ? $this->numericOrNull($item['returned_quantity']) : null;

            return [
                'name' => (string) ($item['name'] ?? $item['item_name'] ?? $item['product_name'] ?? ''),
                'variant' => (string) ($item['variant'] ?? $item['variant_name'] ?? ''),
                'sku' => (string) ($item['sku'] ?? ''),
                'image_url' => (string) ($item['image_url'] ?? ''),
                'quantity' => $qty,
                'returned_quantity' => $returned,
                'actual_quantity' => $returned === null ? null : max(0, $qty - $returned),
                'unit_price' => $this->numericOrNull($item['unit_price'] ?? null),
                'total_price' => $this->numericOrNull($item['total_price'] ?? $item['subtotal_price'] ?? null),
                'unit_cogs' => $this->numericOrNull($item['unit_cogs'] ?? $item['unit_cogs_hpp'] ?? null),
                'total_cogs' => $this->numericOrNull($item['total_cogs'] ?? $item['subtotal_cogs'] ?? null),
            ];
        }, $items) : [];
        $packages = $this->packages($snapshot);
        $timeline = $this->timeline($snapshot);
        $sourceStatus = (string) ($snapshot['source_status'] ?? $row['order_status'] ?? '');
        $profit = $net !== null && $hpp !== null ? $net - $hpp : null;

        return [
            'platform' => (string) ($row['platform'] ?? ''), 'order_id' => (string) ($row['id'] ?? ''),
            'tracking_number' => (string) ($snapshot['tracking_number'] ?? $row['tracking_number'] ?? ''),
            'payment_method' => (string) ($snapshot['payment_method'] ?? $row['payment_method'] ?? ''),
            'is_cod' => $snapshot['is_cod'] ?? null, 'is_estimated' => $snapshot['is_estimated'] ?? null,
            'finance_schema' => $snapshot['finance_schema'] ?? null, 'finance_updated_at' => $snapshot['finance_updated_at'] ?? null,
            'finance_revenue' => $snapshot['finance_revenue'] ?? null, 'subtotal_before_discount' => $snapshot['finance_subtotal_before_discount'] ?? null,
            'seller_discount' => $snapshot['finance_seller_discount'] ?? null, 'unsettled_reason' => $snapshot['unsettled_reason'] ?? null,
            'source_status' => $sourceStatus, 'status' => self::statusLabel($sourceStatus), 'order_date' => $row['order_date'] ?? null,
            'released_at' => $snapshot['released_at'] ?? $row['completion_date'] ?? null, 'source_updated_at' => $snapshot['source_updated_at'] ?? null,
            'items' => $items, 'components' => $components, 'gross_amount' => $gross, 'total_fees' => $fees,
            'net_income' => $net, 'total_cogs' => $hpp, 'net_profit' => $profit,
            'packages' => $packages, 'timeline' => $timeline,
            'financial_state' => $net === null ? 'waiting_income' : ($hpp === null ? 'waiting_hpp' : 'complete'),
            'fee_percent' => $gross > 0 && $fees !== null ? round($fees / $gross * 100, 1) : null,
            'profit_percent' => $gross > 0 && $profit !== null ? round($profit / $gross * 100, 1) : null,
            'adjustment_amount' => $snapshot['adjustment_amount'] ?? $row['adjustment_amount'] ?? null,
            'reconciliation_difference' => $net === null || $fees === null ? null : round(($gross - $fees + (float) ($snapshot['adjustment_amount'] ?? $row['adjustment_amount'] ?? 0)) - $net, 2),
            'component_difference' => $fees === null ? null : round($fees - $componentTotal, 2),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function returnRows(string $storeId): array
    {
        if (! $this->hasTable('marketplace_sync_records')) {
            return [];
        }

        return $this->connection()->table('marketplace_sync_records')
            ->where('store_id', $storeId)->where('resource_type', 'return')->get()
            ->map(static fn ($row): array => (array) $row)->all();
    }

    /** @param list<array<string, mixed>> $rows */
    private function normalizeReturns(array $rows, string $platform): array
    {
        $out = [];
        foreach ($rows as $row) {
            $payload = $this->json($row['payload_json'] ?? null);
            $status = (string) ($payload['return_status'] ?? $payload['status'] ?? '');
            $type = (string) ($payload['return_type'] ?? '');
            $complete = $platform === 'TikTok Shop' && $status === 'RETURN_OR_REFUND_REQUEST_COMPLETE';
            $amount = $payload['refund_amount'] ?? null;
            if (is_array($amount)) {
                $amount = $amount['amount'] ?? null;
            }
            $orderId = (string) ($payload['order_id'] ?? '');
            if ($orderId === '') {
                continue;
            }
            $case = [
                'id' => (string) ($payload['return_id'] ?? $payload['id'] ?? ''), 'order_id' => $orderId, 'type' => $type,
                'type_label' => match ($type) {
                    'REFUND' => 'Dana Saja', 'RETURN_AND_REFUND' => 'Barang & Dana', 'REPLACEMENT' => 'Penggantian', 'EXCHANGE' => 'Penukaran', default => 'Jenis belum dipetakan'
                },
                'source_status' => $status, 'status' => $this->returnStatus($status, $platform),
                'refund_status' => $complete ? 'Refund selesai' : ($status === 'RETURN_OR_REFUND_REQUEST_SUCCESS' ? 'Refund sedang diproses' : 'Belum dikonfirmasi'),
                'refund_amount' => is_numeric($amount) ? (string) $amount : null, 'refund_complete' => $complete,
                'currency' => $payload['currency'] ?? (is_array($payload['refund_amount'] ?? null) ? $payload['refund_amount']['currency'] ?? null : null),
                'reason' => (string) ($payload['return_reason_text'] ?? $payload['return_reason'] ?? ''),
                'tracking' => (string) ($payload['return_tracking_number'] ?? ''), 'courier' => (string) ($payload['return_provider_name'] ?? ''),
                'created' => $payload['create_time'] ?? null, 'updated' => $payload['update_time'] ?? null,
                'items' => $payload['return_line_items'] ?? $payload['return_items'] ?? [],
            ];
            $out[$orderId][] = $case;
        }

        return $out;
    }

    private function returnStatus(string $status, string $platform): string
    {
        $labels = [
            'RETURN_OR_REFUND_REQUEST_PENDING' => 'Diajukan', 'AWAITING_BUYER_SHIP' => 'Menunggu barang dikirim pembeli',
            'BUYER_SHIPPED_ITEM' => 'Barang dalam perjalanan kembali', 'REJECT_RECEIVE_PACKAGE' => 'Penerimaan ditolak',
            'REFUND_OR_RETURN_REQUEST_REJECT' => 'Ditolak', 'RETURN_OR_REFUND_REQUEST_SUCCESS' => 'Refund sedang diproses',
            'RETURN_OR_REFUND_REQUEST_COMPLETE' => 'Refund selesai', 'RETURN_OR_REFUND_REQUEST_CANCEL' => 'Dibatalkan',
        ];

        return $labels[$status] ?? 'Status belum dikenali';
    }

    /** @param list<array<string, mixed>> $cases */
    private function returnMatchesPeriod(array $cases, array $period, string $basis): bool
    {
        foreach ($cases as $case) {
            $value = $case[$basis === 'updated' ? 'updated' : 'created'] ?? null;
            if (is_numeric($value)) {
                $time = (int) $value;
                if ($time >= strtotime($period['start']) && $time < strtotime($period['exclusive_end'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $row */
    private function matchesFilters(array $row, array $filters, string $tab): bool
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $by = (string) ($filters['search_by'] ?? 'order');
        if ($search !== '') {
            $values = match ($by) {
                'sku' => array_column($row['items'] ?? [], 'sku'),
                'product' => array_column($row['items'] ?? [], 'name'),
                'tracking' => [$row['tracking'] ?? ''],
                'recipient' => [$row['recipient'] ?? ''],
                default => [$row['id'] ?? ''],
            };
            $found = false;
            foreach ($values as $value) {
                if (stripos((string) $value, $search) !== false) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return false;
            }
        }
        if (($courier = trim((string) ($filters['courier'] ?? ''))) !== '' && stripos((string) ($row['courier'] ?? ''), $courier) === false) {
            return false;
        }
        if (($payment = (string) ($filters['payment'] ?? '')) === 'cod' && $row['is_cod'] !== true) {
            return false;
        }
        if ($payment === 'non_cod' && $row['is_cod'] !== false) {
            return false;
        }
        $condition = (string) ($filters['condition'] ?? '');
        if ($condition === 'unpaid' && $row['source_status'] !== 'UNPAID') {
            return false;
        }
        if ($condition === 'cancel_request' && ! $row['cancel_requested']) {
            return false;
        }
        if ($condition === 'partial' && $row['source_status'] !== 'PARTIALLY_SHIPPING') {
            return false;
        }
        if ($condition === 'completed' && ! in_array($row['source_status'], ['COMPLETED', 'Selesai'], true)) {
            return false;
        }
        $returnCases = is_array($row['return_cases'] ?? null) ? $row['return_cases'] : [];
        if ($condition === 'return' && ! $this->hasReturnType($returnCases, ['RETURN_AND_REFUND'])) {
            return false;
        }
        if ($condition === 'refund' && ! $this->hasReturnType($returnCases, ['REFUND'])) {
            return false;
        }

        $returnType = (string) ($filters['return_type'] ?? '');
        if ($returnType !== '') {
            $allowed = match ($returnType) {
                'goods' => ['RETURN_AND_REFUND'],
                'refund' => ['REFUND'],
                'replacement' => ['REPLACEMENT', 'EXCHANGE'],
                default => [],
            };
            if ($allowed === [] || ! $this->hasReturnType($returnCases, $allowed)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string, mixed>> $cases @param list<string> $types */
    private function hasReturnType(array $cases, array $types): bool
    {
        foreach ($cases as $case) {
            if (in_array((string) ($case['type'] ?? ''), $types, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, string>> */
    private function packages(array $raw): array
    {
        $packages = [];
        foreach (($raw['packages'] ?? $raw['package_list'] ?? []) as $package) {
            if (is_array($package)) {
                $packages[] = [
                    'id' => (string) ($package['id'] ?? $package['package_id'] ?? $package['package_number'] ?? ''),
                    'tracking' => (string) ($package['tracking_number'] ?? ''),
                    'courier' => (string) ($package['shipping_provider_name'] ?? $package['shipping_carrier'] ?? ''),
                    'status' => (string) ($package['status'] ?? $package['package_status'] ?? ''),
                ];
            }
        }

        return $packages;
    }

    /** @return list<array<string, string>> */
    private function timeline(array $raw): array
    {
        $timeline = [];
        foreach (['create_time' => 'Dibuat', 'paid_time' => 'Dibayar', 'collection_time' => 'Diserahkan ke kurir', 'delivery_time' => 'Diterima', 'completion_time' => 'Selesai'] as $field => $label) {
            $value = $raw[$field] ?? null;
            if (is_numeric($value) && (int) $value > 0) {
                $timeline[] = ['label' => $label, 'time' => Carbon::createFromTimestamp((int) $value, 'Asia/Jakarta')->format('Y-m-d H:i:s')];
            }
        }

        return $timeline;
    }

    /** @return array<string, mixed> */
    private function json(mixed $value): array
    {
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /** @return array<string, mixed> */
    private function resolveStore(array $stores, ?string $requestedStore): array
    {
        if ($requestedStore !== null) {
            foreach ($stores as $store) {
                if ((string) $store['id'] === $requestedStore) {
                    return $store;
                }
            }
            throw new DomainException('Store Marketplace tidak diizinkan.', self::STORE_FORBIDDEN);
        }
        if (count($stores) === 1) {
            return $stores[0];
        }

        throw new DomainException('Pilih store Marketplace terlebih dahulu.', self::STORE_SELECTION_REQUIRED);
    }

    /** @return array<string, mixed> */
    private function returnCoverage(string $storeId): array
    {
        if (! $this->hasTable('marketplace_sync_jobs')) {
            return ['available' => false, 'last_success' => null, 'status' => 'unavailable', 'error' => null];
        }
        $last = $this->connection()->table('marketplace_sync_jobs')->where('store_id', $storeId)->where('job_type', 'returns')->orderByDesc('id')->first();
        $success = $this->connection()->table('marketplace_sync_jobs')->where('store_id', $storeId)->where('job_type', 'returns')->where('status', 'succeeded')->max('finished_at');

        return [
            'available' => $success !== null,
            'last_success' => $success,
            'status' => $last ? (string) $last->status : 'unavailable',
            'error' => $last ? ($last->last_error_code ?? null) : null,
        ];
    }

    private function hasTable(string $table): bool
    {
        return $this->connection()->getSchemaBuilder()->hasTable($table);
    }

    private function connection(): Connection
    {
        return $this->database->connection();
    }
}
