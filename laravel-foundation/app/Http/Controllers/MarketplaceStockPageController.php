<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarketplaceStockRequest;
use App\Marketplace\StockReadService;
use App\Pilots\Marketplace\MarketplaceStoreReadService;
use App\Policies\RolePolicy;
use Inertia\Inertia;

/** The Marketplace "Stok" page: marketplace inventory per variant, separate from factory stock. */
final class MarketplaceStockPageController
{
    public function __invoke(MarketplaceStockRequest $request, StockReadService $stock, MarketplaceStoreReadService $stores, RolePolicy $policy): mixed
    {
        $companyKey = (string) $request->attributes->get('company_key');
        $overview = $stores->overview($companyKey);
        $filters = $request->validated();
        $storeId = (string) ($filters['store_id'] ?? $overview['stores'][0]['id'] ?? '');
        abort_if($storeId === '', 422, 'Pilih toko terlebih dahulu.');
        $filters['store_id'] = $storeId;

        try {
            $data = $stock->listing($companyKey, $storeId, $filters);
        } catch (\DomainException $exception) {
            abort(403, $exception->getMessage());
        } catch (\InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        if ($request->input('format') === 'json' || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
            return response()->json(['stock' => $data, 'filters' => $filters])->header('Cache-Control', 'no-store, private');
        }

        return Inertia::render('Marketplace/Stock', [
            'stock' => $data,
            'filters' => $filters,
            'stores' => $overview['stores'],
            'scope' => ['company_key' => $companyKey, 'store_id' => $storeId],
            'can_edit_minimum' => $policy->allows($request->user('web'), 'settings.manage'),
        ]);
    }
}
