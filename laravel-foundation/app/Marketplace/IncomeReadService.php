<?php

namespace App\Marketplace;

use App\Services\Marketplace\Income\IncomeService;
use App\Services\Marketplace\MarketplaceScope;
use App\Services\Marketplace\Withdrawal\WithdrawalService;

/** Income overview and withdrawal history, trimmed to what the page displays. */
final class IncomeReadService
{
    public function __construct(
        private readonly MarketplaceScope $scope,
        private readonly IncomeService $income,
        private readonly WithdrawalService $withdrawals,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function income(string $companyKey, array $filters): array
    {
        $data = $this->scope->forCompany($companyKey, fn (): array => $this->income->overview($filters));
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
        return $this->scope->forCompany($companyKey, fn (): array => $this->withdrawals->listing($filters));
    }
}
