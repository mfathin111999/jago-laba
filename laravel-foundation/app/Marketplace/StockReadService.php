<?php

namespace App\Marketplace;

use App\Services\Marketplace\MarketplaceScope;
use App\Services\Marketplace\Stock\StockService;

/** Reads the store stock listing; never returns the raw provider payload. */
final class StockReadService
{
    public function __construct(
        private readonly MarketplaceScope $scope,
        private readonly StockService $stock,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listing(string $companyKey, string $storeId, array $filters): array
    {
        $data = $this->scope->forCompany($companyKey, fn (): array => $this->stock->listing($storeId, $filters));
        $data['rows'] = array_map(static function (array $row): array {
            unset($row['payload_json']);

            return $row;
        }, $data['rows']);

        return $data;
    }
}
