<?php

namespace App\Marketplace;

use PDO;

/** Product advertising performance from imported ad reports, through the legacy marketing dashboard. */
final class MarketingReadService
{
    /** Column order the CSV import requires; the page offers it as a template. */
    public const TEMPLATE_COLUMNS = ['marketplace_item_id', 'sku', 'product_name', 'campaign_name', 'period_start', 'period_end', 'ad_spend', 'gross_revenue', 'orders_count', 'qty_sold', 'unit_cogs_hpp', 'platform_fee', 'affiliate_fee'];

    public function __construct(private readonly LegacyReadContext $context) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function dashboard(string $companyKey, string $storeId, array $filters): array
    {
        LegacyRuntime::service('MarketplaceService');

        return $this->context->run($companyKey, fn (PDO $pdo): array => (new \MarketplaceService($pdo))->getMarketingDashboard(
            $storeId,
            $filters['filter'] ?? null,
            $filters['search'] ?? null,
            $filters['platform'] ?? null,
            $filters['ad_type'] ?? 'Semua',
            (bool) ($filters['include_ppn'] ?? false),
            array_filter(['date_start' => $filters['date_start'] ?? null, 'date_end' => $filters['date_end'] ?? null]),
        ));
    }
}
