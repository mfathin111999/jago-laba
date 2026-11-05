<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarketplaceCatalogRequest;
use App\Http\Responses\CsvDownload;
use App\Marketplace\CatalogReadService;
use App\Marketplace\LiveGateway;
use App\Services\Marketplace\MarketplaceStoreReadService;
use App\Policies\RolePolicy;
use Inertia\Inertia;

final class MarketplaceCatalogController
{
    public function __invoke(MarketplaceCatalogRequest $request, CatalogReadService $catalog, MarketplaceStoreReadService $stores, RolePolicy $policy, LiveGateway $live): mixed
    {
        $filters = $request->validated();
        $overview = $stores->overview($request->companyKey());
        $filters['store_id'] = (string) ($filters['store_id'] ?? $overview['stores'][0]['id'] ?? '');
        abort_if($filters['store_id'] === '', 422, 'Pilih toko terlebih dahulu.');
        try {
            $data = $catalog->listing($request->companyKey(), $filters['store_id'], $filters);
        } catch (\DomainException $exception) {
            abort(403, $exception->getMessage());
        }
        if (($filters['format'] ?? '') === 'csv') {
            return CsvDownload::stream(
                'katalog-marketplace.csv',
                ['Produk', 'SKU', 'Varian', 'Harga', 'Stok', 'HPP', 'Terjual'],
                (static function () use ($data): \Generator {
                    foreach ($data['products'] as $product) {
                        foreach ($product['variants'] as $variant) {
                            yield [$product['name'], $variant['sku'], $variant['variant_name'], $variant['price'] ?? '', $variant['stock_qty'] ?? '', $variant['hpp'] ?? '', $variant['sold']];
                        }
                    }
                })(),
                bom: false,
            );
        }
        $props = ['catalog' => $data, 'filters' => $filters, 'read_only' => true];
        if ($request->expectsJson() || ($filters['format'] ?? '') === 'json') {
            return response()->json($props)->header('Cache-Control', 'no-store, private');
        }

        return Inertia::render('Marketplace/Catalog', $props + [
            'stores' => $overview['stores'],
            'scope' => ['company_key' => $request->companyKey(), 'store_id' => $filters['store_id']],
            'can_edit_hpp' => $policy->allows($request->user('web'), 'hpp.edit'),
            'provider' => ['live' => $live->enabled(), 'can_edit_price' => $policy->allows($request->user('web'), 'price.manage')],
        ]);
    }
}
