<?php

namespace App\Services\Marketplace\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * The global analytics report: totals, per-store, per-day, fee breakdown and product lists for a period (1–45 days)
 * and its previous period. Money that the platform has not confirmed stays null, never zero.
 */
final class AnalyticsReport
{
    private const TOTAL_KEYS = ['revenue', 'fees', 'net_income', 'cogs', 'gross_profit', 'expenses', 'ads', 'adjustments', 'net_profit', 'orders', 'cancelled', 'returns', 'pending_revenue', 'pending_net', 'missing_hpp', 'missing_income', 'missing_fees', 'unreconciled', 'known_profit_sum', 'profit_complete_orders', 'final_orders', 'estimated_orders', 'unknown_orders', 'ready', 'pickup', 'shipped', 'completed', 'cancel_requested', 'unpaid', 'review'];

    private const FLOAT_KEYS = ['revenue', 'fees', 'net_income', 'cogs', 'gross_profit', 'expenses', 'ads', 'adjustments', 'net_profit', 'pending_revenue', 'pending_net', 'known_profit_sum'];

    public function __construct(private readonly DatabaseManager $database) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function report(string $companyKey, array $filters = []): array
    {
        // One transaction so every query reads the same snapshot.
        return $this->connection()->transaction(fn (): array => $this->build($companyKey, $filters));
    }

    private function connection(): Connection
    {
        return $this->database->connection();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function build(string $companyKey, array $filters): array
    {
        $db = $this->connection();
        $available = $this->rows($db->select(
            'SELECT s.id,s.store_name,s.platform,s.authorization_status,c.status oauth_status,c.verified_at,c.last_successful_sync_at FROM marketplace_stores s LEFT JOIN marketplace_oauth_connections c ON c.store_id=s.id AND c.company_key=s.company_key WHERE s.company_key=? AND s.archived_at IS NULL ORDER BY s.store_name',
            [$companyKey],
        ));

        $selected = $filters['analytics_stores'] ?? [];
        if (! is_array($selected)) {
            throw new InvalidArgumentException('Pilihan toko tidak valid.');
        }
        $availableIds = array_column($available, 'id');
        foreach ($selected as $id) {
            if (! is_string($id) || ! in_array($id, $availableIds, true)) {
                throw new DomainException('Toko analitik tidak tersedia dalam perusahaan aktif.');
            }
        }
        if (! empty($filters['store_id'])) {
            $selected = [(string) $filters['store_id']];
        }
        if (array_diff($selected, $availableIds)) {
            throw new DomainException('Toko tidak dapat diakses.');
        }

        $stores = array_values(array_filter($available, static function (array $store) use ($filters, $selected): bool {
            return (! $selected || in_array($store['id'], $selected, true))
                && (empty($filters['platform']) || $store['platform'] === $filters['platform'])
                && (! empty($filters['include_disconnected']) || ($store['oauth_status'] ?? $store['authorization_status']) !== 'disconnected');
        }));

        if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true) && $db->getSchemaBuilder()->hasTable('marketplace_integration_bindings')) {
            $environments = collect($db->select('SELECT c.store_id,b.environment FROM marketplace_integration_bindings b JOIN marketplace_oauth_connections c ON c.id=b.connection_id WHERE c.company_key=?', [$companyKey]))
                ->pluck('environment', 'store_id')->all();
            foreach ($stores as &$store) {
                $store['environment'] = $environments[$store['id']] ?? 'unspecified';
            }
            unset($store);
        }

        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Jakarta'));
        $start = $this->date((string) ($filters['date_start'] ?? ''), $today->modify('first day of this month')->format('Y-m-d'));
        $end = $this->date((string) ($filters['date_end'] ?? ''), $today->format('Y-m-d'));
        $days = (int) (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->format('%r%a') + 1;
        if ($days < 1 || $days > 45) {
            throw new InvalidArgumentException('Rentang analitik harus 1–45 hari.');
        }
        $previousStart = (new DateTimeImmutable($start))->modify("-$days days")->format('Y-m-d');
        $previousEnd = (new DateTimeImmutable($start))->modify('-1 day')->format('Y-m-d');
        $basis = ($filters['date_basis'] ?? '') === 'released' ? 'released' : 'created';
        $mode = ($filters['analytics_mode'] ?? '') === 'settled' ? 'settled' : 'all';

        [$orders, $expenses] = $stores === [] ? [[], []] : $this->load($stores, $basis, $previousStart, $end);

        return [
            'timezone' => 'Asia/Jakarta',
            'currency' => 'IDR',
            'current' => $this->aggregate($orders, $expenses, $stores, $start, $end, $basis),
            'previous' => $this->aggregate($orders, $expenses, $stores, $previousStart, $previousEnd, $basis),
            'stores' => $stores,
            'available_stores' => $available,
            'start' => $start,
            'end' => $end,
            'previous_start' => $previousStart,
            'previous_end' => $previousEnd,
            'date_basis' => $basis,
            'mode' => $mode,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Every matching order and expense of the period and the one before it (no UI pagination limit).
     *
     * @param  list<array<string, mixed>>  $stores
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function load(array $stores, string $basis, string $previousStart, string $end): array
    {
        $db = $this->connection();
        $ids = array_column($stores, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $upper = (new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d');

        $where = "store_id IN ($in) AND source_kind IN ('api','import') AND (internal_state IS NULL OR internal_state='active')";
        $params = $ids;
        if ($basis === 'created') {
            $where .= ' AND order_date>=? AND order_date<?';
            array_push($params, $previousStart, $upper);
        }
        $orders = $this->rows($db->select("SELECT * FROM marketplace_orders WHERE $where", $params));
        $expenses = $this->rows($db->select(
            "SELECT * FROM marketplace_expenses WHERE store_id IN ($in) AND expense_date>=? AND expense_date<? AND affects_profit=1 AND is_cashflow_only=0",
            array_merge($ids, [$previousStart, $upper]),
        ));

        return [$orders, $expenses];
    }

    /**
     * @param  array<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private function rows(array $rows): array
    {
        return array_map(static fn (object $row): array => (array) $row, $rows);
    }

    private function date(string $value, string $default): string
    {
        if ($value === '') {
            return $default;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Tanggal analitik tidak valid.');
        }

        return $value;
    }

    /** @return array<string, int|float> */
    private function emptyTotals(): array
    {
        $totals = array_fill_keys(self::TOTAL_KEYS, 0);
        foreach (self::FLOAT_KEYS as $key) {
            $totals[$key] = 0.0;
        }

        return $totals;
    }

    /**
     * @param  array<string, mixed>  $totals
     * @return array<string, mixed>
     */
    private function finish(array $totals): array
    {
        // Source net income already includes marketplace adjustments. Subtract HPP once.
        $totals['known_profit_after_hpp'] = $totals['profit_complete_orders'] > 0 ? $totals['known_profit_sum'] : null;
        $totals['known_operating_subtotal'] = $totals['known_profit_after_hpp'] === null ? null : $totals['known_profit_after_hpp'] - $totals['expenses'];
        $complete = $totals['missing_income'] === 0 && $totals['missing_hpp'] === 0 && $totals['unreconciled'] === 0;
        $totals['gross_profit'] = $complete ? ($totals['known_profit_after_hpp'] ?? 0) : null;
        $totals['gross_margin'] = $complete && $totals['revenue'] != 0 ? $totals['gross_profit'] / $totals['revenue'] * 100 : null;
        // Expense records do not prove complete advertising/operational cost coverage.
        $totals['cost_coverage_complete'] = false;
        $totals['net_profit'] = null;
        $totals['net_margin'] = null;
        $totals['roi'] = null;
        $totals['roas'] = $totals['ads'] > 0 ? $totals['revenue'] / $totals['ads'] : null;
        $totals['average_order'] = $totals['orders'] > 0 ? $totals['revenue'] / $totals['orders'] : null;
        $totals['cost_per_order'] = $totals['orders'] > 0 ? $totals['expenses'] / $totals['orders'] : null;
        $totals['expense_ratio'] = $totals['revenue'] > 0 ? $totals['expenses'] / $totals['revenue'] * 100 : null;
        $totals['operating'] = $totals['expenses'] - $totals['ads'];
        $totals['complete_orders'] = $totals['orders'] - $totals['unknown_orders'];
        if ($totals['missing_income'] > 0) {
            $totals['net_income'] = null;
            $totals['pending_net'] = null;
        }
        if ($totals['missing_hpp'] > 0) {
            $totals['cogs'] = null;
        }
        if ($totals['missing_fees'] > 0) {
            $totals['fees'] = null;
        }

        return $totals;
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     * @param  list<array<string, mixed>>  $expenses
     * @param  list<array<string, mixed>>  $stores
     * @return array<string, mixed>
     */
    private function aggregate(array $orders, array $expenses, array $stores, string $start, string $end, string $basis): array
    {
        $all = $this->emptyTotals();
        $settled = $this->emptyTotals();
        $byStore = [];
        $daily = [];
        $fees = ['all' => [], 'settled' => []];
        $sources = [];
        $products = [];
        $missingRelease = 0;
        foreach ($stores as $store) {
            $byStore[$store['id']] = ['store' => $store, 'all' => $this->emptyTotals(), 'settled' => $this->emptyTotals()];
        }
        for ($date = new DateTimeImmutable($start); $date <= new DateTimeImmutable($end); $date = $date->modify('+1 day')) {
            $daily[$date->format('Y-m-d')] = ['all' => $this->emptyTotals(), 'settled' => $this->emptyTotals()];
        }

        foreach ($orders as $order) {
            $snapshot = json_decode((string) ($order['transaction_detail_json'] ?? ''), true) ?: [];
            $p = OrderPresentation::build($order);
            $paid = $p['net_income'] !== null && (($snapshot['is_estimated'] ?? null) === false || ($snapshot['settlement_received'] ?? false) === true || ($order['escrow_status'] ?? '') === 'Sudah Dicairkan');
            $date = $basis === 'released' ? ($snapshot['released_at'] ?? null) : ($order['order_date'] ?? null);
            if (! $date) {
                if ($basis === 'released') {
                    $missingRelease++;
                }

                continue;
            }
            $date = substr((string) $date, 0, 10);
            if ($date < $start || $date > $end) {
                continue;
            }
            $source = (string) ($order['source_kind'] ?? 'unknown');
            $sources[$source] = ($sources[$source] ?? 0) + 1;

            $delta = $this->orderDelta($order, $snapshot, $p, $paid, $fees, $products);

            foreach ($delta as $key => $value) {
                $all[$key] += $value;
                $byStore[$order['store_id']]['all'][$key] += $value;
                $daily[$date]['all'][$key] += $value;
                if ($paid) {
                    $settled[$key] += $value;
                    $byStore[$order['store_id']]['settled'][$key] += $value;
                    $daily[$date]['settled'][$key] += $value;
                }
            }
        }

        foreach ($expenses as $expense) {
            $date = substr((string) $expense['expense_date'], 0, 10);
            if ($date < $start || $date > $end) {
                continue;
            }
            $amount = (float) $expense['amount'];
            $isAds = preg_match('/iklan|advertising|\bads\b/i', (string) $expense['category']) === 1;
            // Expenses follow their own date in both financial views; never count product ad reports again.
            foreach (['all', 'settled'] as $bucket) {
                $byStore[$expense['store_id']][$bucket]['expenses'] += $amount;
                $daily[$date][$bucket]['expenses'] += $amount;
                if ($isAds) {
                    $byStore[$expense['store_id']][$bucket]['ads'] += $amount;
                    $daily[$date][$bucket]['ads'] += $amount;
                }
            }
            $all['expenses'] += $amount;
            $settled['expenses'] += $amount;
            if ($isAds) {
                $all['ads'] += $amount;
                $settled['ads'] += $amount;
            }
        }

        foreach ($byStore as &$store) {
            foreach (['all', 'settled'] as $bucket) {
                $store[$bucket] = $this->finish($store[$bucket]);
            }
        }
        unset($store);
        foreach ($daily as &$day) {
            foreach (['all', 'settled'] as $bucket) {
                $day[$bucket] = $this->finish($day[$bucket]);
            }
        }
        unset($day);
        foreach (['all', 'settled'] as $bucket) {
            foreach ($fees[$bucket] as &$fee) {
                $fee['effect'] = $fee['amount'] < 0 ? -1 : 1;
                $fee['amount'] = abs($fee['amount']);
            }
            unset($fee);
            uasort($fees[$bucket], static fn ($a, $b) => abs($b['amount']) <=> abs($a['amount']));
        }
        uasort($products, static fn ($a, $b) => $b['quantity'] <=> $a['quantity']);

        return [
            'products' => array_values($products),
            'all' => $this->finish($all),
            'settled' => $this->finish($settled),
            'by_store' => array_values($byStore),
            'daily' => $daily,
            'fees' => $fees,
            'sources' => $sources,
            'missing_release_dates' => $missingRelease,
        ];
    }

    /**
     * What one order adds to the totals; also collects its fee components and products.
     *
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $p  OrderPresentation::build()
     * @param  array<string, array<string, array<string, mixed>>>  $fees
     * @param  array<string, array<string, mixed>>  $products
     * @return array<string, int|float>
     */
    private function orderDelta(array $order, array $snapshot, array $p, bool $paid, array &$fees, array &$products): array
    {
        $cancelled = in_array($p['source_status'], ['Dibatalkan', 'CANCELLED', 'Batal'], true);
        $returned = in_array($p['source_status'], ['Retur', 'RETURNED', 'RETURN_REFUND'], true);
        $unpaid = in_array($p['source_status'], ['UNPAID', 'Belum Dibayar'], true);

        $delta = $this->emptyTotals();
        $delta['cancelled'] = $cancelled ? 1 : 0;
        $delta['returns'] = ($returned || ! empty($snapshot['has_return'])) ? 1 : 0;
        $statusKey = match ($p['status']) {
            'Perlu Dikirim' => 'ready',
            'Menunggu Pickup' => 'pickup',
            'Sedang Dikirim', 'Dikirim Sebagian', 'Terkirim' => 'shipped',
            'Selesai', 'Sudah Diterima' => 'completed',
            'Pengajuan Pembatalan' => 'cancel_requested',
            'Belum Dibayar' => 'unpaid',
            default => 'review',
        };
        if (! $cancelled && ! $returned) {
            $delta[$statusKey] = 1;
        }
        if ($cancelled || $unpaid) {
            return $delta;
        }

        $delta['orders'] = 1;
        $delta['revenue'] = is_numeric($p['finance_revenue']) ? (float) $p['finance_revenue'] : $p['gross_amount'];
        $delta['fees'] = $p['net_income'] !== null ? $delta['revenue'] - $p['net_income'] : ($p['total_fees'] ?? 0);
        $delta['net_income'] = $p['net_income'] ?? 0;
        $delta['adjustments'] = (float) ($p['adjustment_amount'] ?? 0);
        if (! empty($snapshot['has_return']) && array_filter($p['items'], static fn ($item) => $item['returned_quantity'] === null)) {
            $p['total_cogs'] = null;
        }
        $delta['cogs'] = $p['total_cogs'] ?? 0;
        $delta['final_orders'] = $paid ? 1 : 0;
        $delta['estimated_orders'] = $p['net_income'] !== null && ! $paid ? 1 : 0;
        $delta['unknown_orders'] = $p['net_income'] === null ? 1 : 0;
        $delta['missing_hpp'] = $p['total_cogs'] === null ? 1 : 0;
        $delta['missing_income'] = $p['net_income'] === null ? 1 : 0;
        $delta['missing_fees'] = $p['total_fees'] === null ? 1 : 0;
        $delta['unreconciled'] = abs($p['reconciliation_difference'] ?? 0) >= 1 || abs($p['component_difference'] ?? 0) >= 1 ? 1 : 0;
        if (! $delta['missing_hpp'] && ! $delta['missing_income'] && ! $delta['unreconciled']) {
            $delta['known_profit_sum'] = $p['net_income'] - $p['total_cogs'];
            $delta['profit_complete_orders'] = 1;
        }
        if (! $paid) {
            $delta['pending_revenue'] = $delta['revenue'];
            $delta['pending_net'] = $p['net_income'] ?? 0;
        }

        $this->collectProducts($order, $snapshot, $products);
        foreach ($p['components'] as $component) {
            foreach ($paid ? ['all', 'settled'] : ['all'] as $bucket) {
                $key = $component['key'];
                if (! isset($fees[$bucket][$key])) {
                    $fees[$bucket][$key] = ['label' => $component['label'], 'effect' => 1, 'amount' => 0.0];
                }
                $fees[$bucket][$key]['amount'] += $component['amount'] * $component['effect'];
            }
        }

        return $delta;
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, array<string, mixed>>  $products
     */
    private function collectProducts(array $order, array $snapshot, array &$products): void
    {
        foreach ($snapshot['items'] ?? [] as $item) {
            $productId = (string) ($item['external_product_id'] ?? $item['product_id'] ?? '');
            $variantId = (string) ($item['external_variant_id'] ?? $item['variant_id'] ?? $item['sku_id'] ?? '');
            $key = hash('sha256', json_encode([$order['store_id'], $productId, $variantId, ($productId !== '' && $variantId !== '') ? null : [$item['sku'] ?? $item['seller_sku'] ?? '', $item['name'] ?? $item['product_name'] ?? '', $item['variant'] ?? $item['variant_name'] ?? '']]));
            if (! isset($products[$key])) {
                $products[$key] = ['name' => (string) ($item['name'] ?? $item['product_name'] ?? ''), 'variant' => (string) ($item['variant'] ?? $item['variant_name'] ?? ''), 'sku' => (string) ($item['sku'] ?? $item['seller_sku'] ?? ''), 'quantity' => 0, 'returns' => 0, 'returns_unknown' => false, 'orders' => 0];
            }
            $products[$key]['quantity'] += (float) ($item['quantity'] ?? $item['qty'] ?? 0);
            $products[$key]['orders']++;
            if (isset($item['returned_quantity'])) {
                $products[$key]['returns'] += (float) $item['returned_quantity'];
            } elseif (! empty($snapshot['has_return'])) {
                $products[$key]['returns_unknown'] = true;
            }
        }
    }
}
