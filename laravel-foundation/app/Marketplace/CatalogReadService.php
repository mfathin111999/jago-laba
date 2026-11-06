<?php

namespace App\Marketplace;

use App\Services\Marketplace\Catalog\CatalogService;
use App\Services\Marketplace\MarketplaceScope;

final class CatalogReadService
{
    public function __construct(
        private readonly MarketplaceScope $scope,
        private readonly CatalogService $catalog,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listing(string $companyKey, string $storeId, array $filters): array
    {
        return $this->scope->forCompany($companyKey, fn (): array => $this->catalog->listingForStore($storeId, $filters));
    }
}
