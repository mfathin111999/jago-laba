<?php

namespace App\Marketplace;

use PDO;

/** Income overview and withdrawal history through the legacy services, trimmed to what the page displays. */
final class IncomeReadService
{
    public function __construct(private readonly LegacyReadContext $context) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function income(string $companyKey, array $filters): array
    {
        require_once dirname(__DIR__, 3).'/app/Modules/Marketplace/Services/MarketplaceIncomeService.php';

        $data = $this->context->run($companyKey, fn (PDO $pdo): array => (new \MarketplaceIncomeService($pdo))->overview($filters));
        $data['rows'] = array_map(static fn (array $row): array => [
            'order_id' => (string) ($row['order']['id'] ?? ''),
            'order_date' => $row['order']['order_date'] ?? null,
            'store_name' => $row['order']['store_name'] ?? null,
            'platform' => $row['order']['platform'] ?? null,
            'payout_batch_id' => $row['order']['payout_batch_id'] ?? null,
            'state' => $row['state'],
            'gross_amount' => $row['facts']['gross_amount'] ?? null,
            'total_fees' => $row['facts']['total_fees'] ?? null,
            'net_income' => $row['facts']['net_income'] ?? null,
        ], $data['rows']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function withdrawals(string $companyKey, array $filters): array
    {
        require_once dirname(__DIR__, 3).'/app/Modules/Marketplace/Services/MarketplaceWithdrawalService.php';

        return $this->context->run($companyKey, fn (PDO $pdo): array => (new \MarketplaceWithdrawalService($pdo))->listing($filters));
    }
}
