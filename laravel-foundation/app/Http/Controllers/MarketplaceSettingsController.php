<?php

namespace App\Http\Controllers;

use App\Services\Marketplace\MarketplaceSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class MarketplaceSettingsController
{
    public function __construct(private readonly MarketplaceSettingsService $service) {}

    public function simulate(Request $request): JsonResponse
    {
        try {
            $data = $this->service->simulatePrice($request->all());

            return response()->json(['success' => true, 'data' => $data]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function getSimulations(Request $request): JsonResponse
    {
        $data = $this->service->getSavedPriceSimulations();

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function getStoreDetail(Request $request): JsonResponse
    {
        $storeId = trim((string) ($request->input('store_id') ?? $request->query('store_id') ?? ''));
        if ($storeId === '') {
            return response()->json(['success' => false, 'message' => 'ID Toko wajib diisi.'], 422);
        }

        $detail = $this->service->getStoreDetail($request->companyKey(), $storeId);

        return response()->json(['success' => true, 'data' => $detail]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $res = $this->service->saveAppSettings($request->user('web'), $request->all());

        return response()->json($res);
    }

    /** Legacy contract: no provider call is made until the official connector is approved. */
    public function testConnection(Request $request): JsonResponse
    {
        return $this->inactiveProviderAction($request, 'Koneksi belum diverifikasi ke API resmi. Konfigurasi lama bukan bukti otorisasi toko.');
    }

    public function syncMarketingAds(Request $request): JsonResponse
    {
        return $this->inactiveProviderAction($request, 'Sinkronisasi iklan belum aktif karena sumber resmi belum terhubung.');
    }

    private function inactiveProviderAction(Request $request, string $message): JsonResponse
    {
        $storeId = trim((string) ($request->input('store_id') ?? $request->query('store_id') ?? ''));
        if ($storeId === '') {
            return response()->json(['success' => false, 'message' => 'ID Toko wajib diisi.'], 422);
        }

        if ($this->service->getStoreDetail($request->companyKey(), $storeId) === null) {
            return response()->json(['success' => false, 'message' => 'Toko tidak ditemukan.'], 404);
        }

        return response()->json(['success' => false, 'message' => $message]);
    }
}
