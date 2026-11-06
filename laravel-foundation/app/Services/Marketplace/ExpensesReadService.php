<?php

namespace App\Services\Marketplace;

use App\Services\Marketplace\Expenses\ExpenseListing;

final class ExpensesReadService
{
    public function __construct(
        private readonly MarketplaceScope $scope,
        private readonly ExpenseListing $expenses,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listing(string $companyKey, string $storeId, array $filters): array
    {
        return $this->scope->forCompany($companyKey, fn (): array => $this->expenses->list(
            $storeId, $filters['category'] ?? null, $filters['search'] ?? null, $filters['date'] ?? null,
        ));
    }
}
