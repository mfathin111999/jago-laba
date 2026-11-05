<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarketplaceExpensesPageRequest;
use App\Http\Responses\MarketplaceReportResponse;
use App\Marketplace\ExpensesReadService;
use App\Pilots\Marketplace\MarketplaceStoreReadService;
use App\Policies\RolePolicy;
use Inertia\Inertia;

final class MarketplaceExpensesReadController
{
    public function __invoke(MarketplaceExpensesPageRequest $request, ExpensesReadService $expenses, MarketplaceStoreReadService $stores, RolePolicy $policy): mixed
    {
        $companyKey = $request->companyKey();
        $overview = $stores->overview($companyKey);
        $storeId = (string) ($request->validated('store_id') ?: ($overview['stores'][0]['id'] ?? ''));
        $filters = array_merge($request->validated(), ['store_id' => $storeId]);
        $wantsData = $request->input('format') !== null || $request->expectsJson() && ! $request->header('X-Inertia');

        if ($storeId === '') {
            abort_if($wantsData, 422, 'Pilih toko terlebih dahulu.');

            return Inertia::render('Marketplace/Expenses', $this->page($request, $overview, null, $policy, [], $filters));
        }

        try {
            $data = $expenses->listing($companyKey, $storeId, $filters);
        } catch (\DomainException $exception) {
            abort(403, $exception->getMessage());
        }

        if ($wantsData) {
            return MarketplaceReportResponse::make($request, 'Pengeluaran', $data, 'expenses', [
                'expense_date' => 'Tanggal', 'description' => 'Keterangan', 'category' => 'Kategori', 'amount' => 'Nominal',
                'vendor_name' => 'Vendor', 'affects_profit' => 'Mempengaruhi laba', 'is_cashflow_only' => 'Arus kas saja',
            ]);
        }

        return Inertia::render('Marketplace/Expenses', $this->page($request, $overview, $storeId, $policy, $data, $filters));
    }

    /**
     * @param  array<string, mixed>  $overview
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function page(MarketplaceExpensesPageRequest $request, array $overview, ?string $storeId, RolePolicy $policy, array $data, array $filters): array
    {
        return [
            'stores' => $overview['stores'],
            'scope' => ['company_key' => $request->companyKey(), 'store_id' => $storeId],
            'filters' => array_intersect_key($filters, array_flip(['category', 'search', 'date'])),
            'summary' => array_diff_key($data, ['expenses' => 1]),
            'expenses' => $data['expenses'] ?? [],
            'can_manage' => $policy->allows($request->user('web'), 'expense.manage'),
        ];
    }
}
