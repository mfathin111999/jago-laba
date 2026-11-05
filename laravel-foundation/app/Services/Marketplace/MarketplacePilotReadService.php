<?php

namespace App\Services\Marketplace;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Throwable;

final class MarketplacePilotReadService
{
    public const NO_INTEGRATED_STORE = 1001;

    public const STORE_FORBIDDEN = 1002;

    public const STORE_SELECTION_REQUIRED = 1003;

    public function __construct(private readonly DatabaseManager $database) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(string $companyKey, ?string $requestedStore, array $filters): array
    {
        $stores = $this->stores($companyKey);
        $integrated = array_values(array_filter($stores, static fn (array $store): bool => (string) ($store['oauth_connection_id'] ?? '') !== '' && (string) ($store['oauth_authorized_at'] ?? '') !== '',
        ));

        if ($integrated === []) {
            throw new DomainException('Belum ada store Marketplace yang terhubung.', self::NO_INTEGRATED_STORE);
        }

        $activeStore = $this->resolveStore($integrated, $requestedStore);
        $period = self::period($filters);
        $dashboard = $this->report($activeStore['id'], $period, $filters);

        return [
            'read_only' => true,
            'workspace' => 'marketplace',
            'scope' => ['company_key' => $companyKey, 'store_id' => $activeStore['id']],
            'stores' => $stores,
            'active_store' => $activeStore,
            'filters' => $filters + ['period' => 'month', 'finance_mode' => 'final'],
            'dashboard' => $dashboard,
            'query' => [
                'tab' => 'dashboard',
                'store' => $activeStore['id'],
                'period' => $period,
                'orders' => [
                    'table' => 'marketplace_orders',
                    'where' => [
                        'store_id' => $activeStore['id'],
                        'source_kind' => 'api',
                        'internal_state' => ['null', 'active'],
                        'order_date_gte' => $period['start'],
                        'order_date_lt' => $period['exclusive_end'],
                    ],
                    'order_by' => ['order_date', 'id'],
                ],
                'expenses' => [
                    'table' => 'marketplace_expenses',
                    'where' => [
                        'store_id' => $activeStore['id'],
                        'expense_date_gte' => $period['start'],
                        'expense_date_lt' => $period['exclusive_end'],
                        'affects_profit' => 1,
                        'is_cashflow_only' => 0,
                    ],
                    'order_by' => ['expense_date', 'id'],
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function stores(string $companyKey): array
    {
        $query = $this->database->connection()->table('marketplace_stores as s')
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
            $connected = (string) ($store['oauth_connection_id'] ?? '') !== '' && (string) ($store['oauth_authorized_at'] ?? '') !== '';
            $verified = $connected && (string) ($store['oauth_status'] ?? '') === 'verified' && (string) ($store['oauth_verified_at'] ?? '') !== '';
            $store['authorization_label'] = $verified ? 'Terhubung & terverifikasi' : ($connected ? 'Terhubung' : 'Belum terhubung');
            $store['is_connection_verified'] = $verified;

            return $store;
        })->all();
    }

    /**
     * @param  list<array<string, mixed>>  $stores
     * @return array<string, mixed>
     */
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

    /**
     * @return array<string, string>
     */
    public static function period(array $filters = []): array
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Jakarta'));
        $preset = (string) ($filters['period'] ?? 'month');
        [$start, $end] = match ($preset) {
            'today' => [$today, $today],
            'yesterday' => [$today->modify('-1 day'), $today->modify('-1 day')],
            'week' => [$today->modify('-6 days'), $today],
            'last_month' => [$today->modify('first day of last month'), $today->modify('last day of last month')],
            'custom' => self::customPeriod($filters),
            default => [$today->modify('first day of this month'), $today],
        };

        if ($start > $end || $start->diff($end)->days > 365) {
            throw new InvalidArgumentException('Pilih rentang berurutan maksimal 366 hari.');
        }

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'preset' => $preset,
            'exclusive_end' => $end->modify('+1 day')->format('Y-m-d'),
        ];
    }

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private static function customPeriod(array $filters): array
    {
        $dates = [];
        foreach (['date_start', 'date_end'] as $key) {
            $value = (string) ($filters[$key] ?? '');
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            if (! $date || $date->format('Y-m-d') !== $value) {
                throw new InvalidArgumentException('Tanggal tidak valid.');
            }

            $dates[] = $date;
        }

        return [$dates[0], $dates[1]];
    }

    /**
     * @return array<string, mixed>
     */
    private function report(string $storeId, array $period, array $filters): array
    {
        $orders = $this->database->connection()->table('marketplace_orders')
            ->select(['id', 'source_kind', 'order_date', 'order_status', 'gross_sales', 'transaction_detail_json'])
            ->where('store_id', $storeId)
            ->where('source_kind', 'api')
            ->where(function ($query): void {
                $query->whereNull('internal_state')->orWhere('internal_state', 'active');
            })
            ->where('order_date', '>=', $period['start'])
            ->where('order_date', '<', $period['exclusive_end'])
            ->orderBy('order_date')
            ->orderBy('id')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
        $expenses = $this->database->connection()->table('marketplace_expenses')
            ->select(['id', 'expense_date', 'category', 'description', 'amount'])
            ->where('store_id', $storeId)
            ->where('expense_date', '>=', $period['start'])
            ->where('expense_date', '<', $period['exclusive_end'])
            ->where('affects_profit', 1)
            ->where('is_cashflow_only', 0)
            ->orderBy('expense_date')
            ->orderBy('id')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();

        return self::aggregate($orders, $expenses) + [
            'period' => $period,
            'generated_at' => now()->format('Y-m-d H:i:s'),
            'finance_mode' => (string) ($filters['finance_mode'] ?? 'final'),
        ];
    }

    /**
     * This is deliberately kept as a pure read-side function so the legacy
     * fixture can be compared without booting the old provider or writing DB state.
     *
     * @param  iterable<array<string, mixed>>  $orders
     * @param  list<array<string, mixed>>  $expenses
     * @return array<string, mixed>
     */
    public static function aggregate(iterable $orders, array $expenses): array
    {
        $groups = ['final' => self::bucket(), 'estimated' => self::bucket(), 'unknown' => self::bucket()];
        $daily = [];
        $attention = ['cancelled' => 0, 'unpaid' => 0, 'returns' => 0, 'loss' => 0, 'missing_finance' => 0, 'missing_hpp' => 0, 'unreconciled' => 0, 'cancel_finance' => 0];
        $rows = [];

        foreach ($orders as $order) {
            if (($order['source_kind'] ?? '') !== 'api') {
                continue;
            }

            $snapshot = json_decode((string) ($order['transaction_detail_json'] ?? ''), true);
            $snapshot = is_array($snapshot) ? $snapshot : [];
            $status = self::status($snapshot, (string) ($order['order_status'] ?? ''));
            $net = array_key_exists('net_income', $snapshot) && is_numeric($snapshot['net_income']) ? (float) $snapshot['net_income'] : (array_key_exists('net_income', $snapshot) ? null : null);
            $hpp = array_key_exists('total_cogs', $snapshot) && is_numeric($snapshot['total_cogs']) ? (float) $snapshot['total_cogs'] : null;

            if ($status === 'Batal') {
                $attention['cancelled']++;
                if (($net ?? 0) !== 0.0) {
                    $attention['cancel_finance']++;
                }

                continue;
            }
            if ($status === 'Belum Dibayar') {
                $attention['unpaid']++;

                continue;
            }

            $known = array_key_exists('net_income', $snapshot) && is_numeric($snapshot['net_income']);
            $state = ! $known ? 'unknown' : (($snapshot['is_estimated'] ?? true) === false ? 'final' : 'estimated');
            $sales = is_numeric($snapshot['finance_revenue'] ?? null)
                ? (float) $snapshot['finance_revenue']
                : (is_numeric($snapshot['gross_amount'] ?? null) ? (float) $snapshot['gross_amount'] : (is_numeric($order['gross_sales'] ?? null) ? (float) $order['gross_sales'] : null));
            $unreconciled = $known && isset($snapshot['gross_amount'], $snapshot['total_fees'])
                && is_numeric($snapshot['gross_amount']) && is_numeric($snapshot['total_fees'])
                && abs((float) $snapshot['gross_amount'] - (float) $snapshot['total_fees'] - (float) $net) > 1;
            if (! empty($snapshot['has_return'])) {
                $attention['returns']++;
                foreach (($snapshot['items'] ?? []) as $item) {
                    if (! array_key_exists('returned_quantity', $item)) {
                        $hpp = null;
                    }
                }
            }

            $fees = $sales !== null && $net !== null ? $sales - $net : null;
            $group = &$groups[$state];
            $group['count']++;
            foreach (['sales' => $sales, 'net' => $net, 'hpp' => $hpp] as $key => $value) {
                if ($value === null) {
                    $group['missing_'.$key]++;
                } else {
                    $group[$key.'_known'] += $value;
                }
            }
            if ($fees !== null) {
                $group['fees_known'] += $fees;
            }
            if ($unreconciled) {
                $group['unreconciled']++;
                $attention['unreconciled']++;
            }
            if ($net === null) {
                $attention['missing_finance']++;
            }
            if ($hpp === null) {
                $attention['missing_hpp']++;
            }
            $profit = $net !== null && $hpp !== null && ! $unreconciled ? $net - $hpp : null;
            if ($profit !== null) {
                $group['profit_known'] += $profit;
                $group['profit_count']++;
                if ($profit < 0) {
                    $attention['loss']++;
                }
            }
            $date = substr((string) ($order['order_date'] ?? ''), 0, 10);
            $daily[$date] ??= ['date' => $date, 'sales' => 0.0, 'net' => 0.0, 'profit' => 0.0, 'orders' => 0, 'complete' => true];
            $daily[$date]['orders']++;
            $daily[$date]['sales'] += $sales ?? 0;
            $daily[$date]['net'] += $net ?? 0;
            $daily[$date]['profit'] += $profit ?? 0;
            if ($sales === null || $profit === null) {
                $daily[$date]['complete'] = false;
            }
            unset($group);
            $rows[] = ['id' => $order['id'] ?? null, 'state' => $state, 'sales' => $sales, 'net' => $net, 'hpp' => $hpp, 'profit' => $profit];
        }

        foreach ($groups as &$group) {
            $group['sales'] = $group['count'] && ! $group['missing_sales'] ? $group['sales_known'] : null;
            $group['net'] = $group['count'] && ! $group['missing_net'] ? $group['net_known'] : null;
            $group['hpp'] = $group['count'] && ! $group['missing_hpp'] ? $group['hpp_known'] : null;
            $group['fees'] = $group['sales'] !== null && $group['net'] !== null ? $group['sales'] - $group['net'] : null;
            $group['profit'] = $group['count'] && $group['profit_count'] === $group['count'] ? $group['profit_known'] : null;
        }
        unset($group);

        $costs = ['ads' => null, 'operational' => null, 'count' => 0];
        foreach ($expenses as $expense) {
            if (! is_numeric($expense['amount'] ?? null)) {
                continue;
            }
            $type = preg_match('/iklan|advertising|ads/i', (string) ($expense['category'] ?? '')) ? 'ads' : 'operational';
            $costs[$type] = ($costs[$type] ?? 0) + (float) $expense['amount'];
            $costs['count']++;
        }

        $combined = self::bucket();
        foreach ($groups as $group) {
            foreach (array_keys($combined) as $key) {
                $combined[$key] += $group[$key];
            }
        }

        $subtotal = $combined['profit_count'] > 0 ? $combined['profit_known'] - ($costs['ads'] ?? 0) - ($costs['operational'] ?? 0) : null;

        return ['groups' => $groups, 'attention' => $attention, 'daily' => array_values($daily), 'costs' => $costs, 'rows' => $rows, 'expenses' => $expenses, 'known_subtotal' => $subtotal, 'subtotal_orders' => $combined['profit_count'], 'eligible_orders' => $combined['count'], 'net_profit' => null, 'cost_coverage_complete' => false];
    }

    /**
     * @return array<string, int|float>
     */
    private static function bucket(): array
    {
        return ['count' => 0, 'sales_known' => 0.0, 'net_known' => 0.0, 'hpp_known' => 0.0, 'fees_known' => 0.0, 'missing_sales' => 0, 'missing_net' => 0, 'missing_hpp' => 0, 'unreconciled' => 0, 'profit_known' => 0.0, 'profit_count' => 0];
    }

    private static function status(array $snapshot, string $fallback): string
    {
        $source = (string) ($snapshot['source_status'] ?? $fallback);
        if ($source !== 'CANCELLED' && $source !== 'Dibatalkan' && ($snapshot['is_buyer_request_cancel'] ?? false) === true) {
            return 'Pengajuan Pembatalan';
        }

        return [
            'UNPAID' => 'Belum Dibayar', 'CANCELLED' => 'Batal', 'Dibatalkan' => 'Batal',
        ][$source] ?? $source;
    }

    private function hasTable(string $table): bool
    {
        try {
            return $this->database->connection()->getSchemaBuilder()->hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}
