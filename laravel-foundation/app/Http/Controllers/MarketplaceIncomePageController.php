<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarketplaceIncomeRequest;
use App\Marketplace\IncomeReadService;
use App\Pilots\Marketplace\MarketplaceStoreReadService;
use App\Policies\RolePolicy;
use Inertia\Inertia;

/** The Marketplace "Pemasukan" page: estimated and final income per order, and imported withdrawal history. */
final class MarketplaceIncomePageController
{
    public function __invoke(MarketplaceIncomeRequest $request, IncomeReadService $reader, MarketplaceStoreReadService $stores, RolePolicy $policy): mixed
    {
        $companyKey = $request->companyKey();
        $filters = $request->validated();
        $filters['date_start'] = $filters['date_start'] ?? now('Asia/Jakarta')->startOfMonth()->format('Y-m-d');
        $filters['date_end'] = $filters['date_end'] ?? now('Asia/Jakarta')->format('Y-m-d');
        $tab = $filters['tab'] ?? 'income';
        $storeId = trim((string) ($filters['store_id'] ?? ''));

        try {
            $income = $reader->income($companyKey, $filters);
            $withdrawals = $reader->withdrawals($companyKey, [
                'store_id' => $storeId, 'platform' => $filters['platform'] ?? '', 'date_start' => $filters['date_start'], 'date_end' => $filters['date_end'],
                'status' => $filters['payout_status'] ?? '', 'date_basis' => $filters['payout_date_basis'] ?? 'requested', 'page' => $filters['payout_page'] ?? 1,
                'search' => $filters['search'] ?? '',
            ]);
        } catch (\DomainException $exception) {
            abort(403, $exception->getMessage());
        } catch (\InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        $props = ['income' => $income, 'withdrawals' => $withdrawals, 'filters' => $filters + ['tab' => $tab]];
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($props)->header('Cache-Control', 'no-store, private');
        }

        return Inertia::render('Marketplace/Income', $props + [
            'stores' => $stores->overview($companyKey)['stores'],
            'scope' => ['company_key' => $companyKey, 'store_id' => $storeId !== '' ? $storeId : null],
            'can_import' => $policy->allows($request->user('web'), 'withdrawal.manage'),
        ]);
    }
}
