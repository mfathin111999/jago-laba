<?php

namespace App\Marketplace;

use PDO;

final class CatalogReadService
{
    public function __construct(private readonly LegacyReadContext $context) {}

    public function listing(string $companyKey, string $storeId, array $filters): array
    {
        LegacyRuntime::service('MarketplaceCatalogService');

        return $this->context->run($companyKey, fn (PDO $pdo): array => (new \MarketplaceCatalogService($pdo))->listingForStore($storeId, $filters));
    }
}
