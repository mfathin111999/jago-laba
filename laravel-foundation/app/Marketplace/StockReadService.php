<?php

namespace App\Marketplace;

use PDO;

/** Reads the store stock listing through the legacy service; never returns the raw provider payload. */
final class StockReadService
{
    public function __construct(private readonly LegacyReadContext $context) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listing(string $companyKey, string $storeId, array $filters): array
    {
        require_once dirname(__DIR__, 3).'/app/Modules/Marketplace/Services/MarketplaceStockService.php';

        $data = $this->context->run($companyKey, fn (PDO $pdo): array => (new \MarketplaceStockService($pdo))->listing($storeId, $filters));
        $data['rows'] = array_map(static function (array $row): array {
            unset($row['payload_json']);

            return $row;
        }, $data['rows']);

        return $data;
    }
}
