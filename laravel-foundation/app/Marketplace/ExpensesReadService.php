<?php

namespace App\Marketplace;

use PDO;

final class ExpensesReadService
{
    public function __construct(private readonly LegacyReadContext $context) {}

    public function listing(string $companyKey, string $storeId, array $filters): array
    {
        LegacyRuntime::service('MarketplaceService');

        return $this->context->run($companyKey, fn (PDO $pdo): array => (new \MarketplaceService($pdo))->getExpenses(
            $storeId, $filters['category'] ?? null, $filters['search'] ?? null, $filters['date'] ?? null,
        ));
    }
}
