<?php

namespace App\Http\Controllers;

use App\Marketplace\MarketingReadService;
use App\Services\Marketplace\MarketplaceStoreReadService;
use App\Policies\RolePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

/** The Marketplace "Iklan" page: ad performance from imported reports. Nothing is fetched from an ads provider. */
final class MarketplaceMarketingPageController
{
    public function __invoke(Request $request, MarketingReadService $reader, MarketplaceStoreReadService $stores, RolePolicy $policy): mixed
    {
        $filters = Validator::make($request->all(), [
            'store_id' => ['nullable', 'string', 'max:120'],
            'filter' => ['nullable', 'in:roas_rendah,tanpa_hpp,rugi,bocor'],
            'platform' => ['nullable', 'in:Shopee,TikTok Shop'],
            'ad_type' => ['nullable', 'string', 'max:50'],
            'include_ppn' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:200'],
            'date_start' => ['nullable', 'date_format:Y-m-d'],
            'date_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_start'],
        ])->validate();

        $companyKey = $request->companyKey();
        $overview = $stores->overview($companyKey);
        $storeId = (string) ($filters['store_id'] ?? $overview['stores'][0]['id'] ?? '');
        abort_if($storeId === '', 422, 'Pilih toko terlebih dahulu.');

        try {
            $data = $reader->dashboard($companyKey, $storeId, $filters);
        } catch (\DomainException $exception) {
            abort(403, $exception->getMessage());
        } catch (\InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        $props = ['marketing' => $data, 'filters' => $filters + ['store_id' => $storeId]];
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($props)->header('Cache-Control', 'no-store, private');
        }

        return Inertia::render('Marketplace/Marketing', $props + [
            'stores' => $overview['stores'],
            'scope' => ['company_key' => $companyKey, 'store_id' => $storeId],
            'can_import' => $policy->allows($request->user('web'), 'finance.manage'),
        ]);
    }

    public function template(): mixed
    {
        return response()->streamDownload(function (): void {
            echo implode(',', MarketingReadService::TEMPLATE_COLUMNS)."\r\n";
        }, 'template-laporan-iklan-produk.csv', ['Content-Type' => 'text/csv; charset=utf-8', 'Cache-Control' => 'no-store, private']);
    }
}
