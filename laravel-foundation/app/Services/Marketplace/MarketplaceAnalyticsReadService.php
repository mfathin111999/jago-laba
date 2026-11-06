<?php

namespace App\Services\Marketplace;

use App\Services\Marketplace\Analytics\AnalyticsReport;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Read-only Laravel boundary for the legacy Marketplace analytics contract.
 *
 * The finance/HPP calculations intentionally remain in the legacy read service
 * during this pilot. This keeps the fixture parity exact while Laravel owns
 * authentication, workspace, company, and store scope.
 */
final class MarketplaceAnalyticsReadService
{
    public const NO_INTEGRATED_STORE = 5101;

    public const STORE_FORBIDDEN = 5102;

    public const STORE_SELECTION_REQUIRED = 5103;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly MarketplacePilotReadService $marketplace,
        private readonly AnalyticsReport $report,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function stores(string $companyKey): array
    {
        return $this->marketplace->stores($companyKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(string $companyKey, ?string $requestedStore, array $filters): array
    {
        $stores = $this->marketplace->stores($companyKey);
        $integrated = array_values(array_filter(
            $stores,
            static fn (array $store): bool => (string) ($store['oauth_connection_id'] ?? '') !== ''
                && (string) ($store['oauth_authorized_at'] ?? '') !== '',
        ));

        if ($integrated === []) {
            throw new DomainException('Belum ada store Marketplace yang terhubung.', self::NO_INTEGRATED_STORE);
        }

        $activeStore = $this->resolveStore($integrated, $requestedStore);
        $period = $this->period($filters);
        $dateBasis = in_array((string) ($filters['date_basis'] ?? 'created'), ['created', 'released'], true)
            ? (string) ($filters['date_basis'] ?? 'created')
            : 'created';
        $analyticsMode = in_array((string) ($filters['analytics_mode'] ?? 'all'), ['all', 'settled'], true)
            ? (string) ($filters['analytics_mode'] ?? 'all')
            : 'all';

        $legacyFilters = [
            'store_id' => $activeStore['id'],
            'analytics_stores' => [],
            'include_disconnected' => false,
            'date_start' => $period['start'],
            'date_end' => $period['end'],
            'date_basis' => $dateBasis,
            'analytics_mode' => $analyticsMode,
        ];

        $analytics = $this->report->report($companyKey, $legacyFilters);

        return [
            'read_only' => true,
            'workspace' => 'marketplace',
            'scope' => ['company_key' => $companyKey, 'store_id' => $activeStore['id']],
            'stores' => $stores,
            'active_store' => $activeStore,
            'filters' => [
                'period' => $period['preset'],
                'date_start' => $period['start'],
                'date_end' => $period['end'],
                'date_basis' => $dateBasis,
                'analytics_mode' => $analyticsMode,
            ],
            'analytics' => $analytics,
            'query' => [
                'tab' => 'analitik',
                'store' => $activeStore['id'],
                'tables' => [
                    'marketplace_stores',
                    'marketplace_oauth_connections',
                    'marketplace_orders',
                    'marketplace_expenses',
                    'marketplace_integration_bindings',
                ],
                'where' => [
                    'company_key' => $companyKey,
                    'store_id' => $activeStore['id'],
                    'source_kind' => ['api', 'import'],
                    'internal_state' => ['null', 'active'],
                    'date_basis' => $dateBasis,
                    'date_start' => $period['start'],
                    'date_end_exclusive' => $period['exclusive_end'],
                ],
                'order_by' => ['order_date', 'id'],
                'provider_call' => false,
                'write' => false,
            ],
        ];
    }

    /**
     * @return array{start: string, end: string, exclusive_end: string, preset: string}
     */
    public function period(array $filters): array
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Jakarta'));
        $preset = (string) ($filters['period'] ?? 'month');

        [$start, $end] = match ($preset) {
            'today' => [$today, $today],
            'yesterday' => [$today->modify('-1 day'), $today->modify('-1 day')],
            'week' => [$today->modify('-6 days'), $today],
            'last_month' => [$today->modify('first day of last month'), $today->modify('last day of last month')],
            'custom' => $this->customPeriod($filters),
            'month' => [$today->modify('first day of this month'), $today],
            default => throw new InvalidArgumentException('Periode analitik tidak valid.'),
        };

        $days = (int) $start->diff($end)->format('%r%a') + 1;
        if ($start > $end || $days < 1 || $days > 45) {
            throw new InvalidArgumentException('Rentang analitik harus 1–45 hari.');
        }

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'exclusive_end' => $end->modify('+1 day')->format('Y-m-d'),
            'preset' => $preset,
        ];
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
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function customPeriod(array $filters): array
    {
        $dates = [];
        foreach (['date_start', 'date_end'] as $key) {
            $value = (string) ($filters[$key] ?? '');
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            if (! $date || $date->format('Y-m-d') !== $value) {
                throw new InvalidArgumentException('Tanggal analitik tidak valid.');
            }

            $dates[] = $date;
        }

        return [$dates[0], $dates[1]];
    }

}
