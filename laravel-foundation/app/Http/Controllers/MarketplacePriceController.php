<?php

namespace App\Http\Controllers;

use App\Authorization\StoreContext;
use App\Security\CsrfTokenValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Legacy-compatible POST /api/marketplace-price.php (actions: prepare, save).
 *
 * Both actions read from, or write prices and promotions to, the marketplace provider. Provider
 * calls are disabled until the official connector is approved, so the request is validated
 * (method, CSRF, role, store scope, action) and then answered as unavailable without any call.
 */
final class MarketplacePriceController
{
    public function __construct(
        private readonly StoreContext $stores,
        private readonly CsrfTokenValidator $csrf,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->csrf->valid($request)) {
            return $this->error('Token CSRF tidak valid.', 'CSRF_INVALID', 419);
        }

        $action = (string) $request->input('action', '');
        if (! in_array($action, ['prepare', 'save'], true)) {
            return $this->error('Aksi tidak valid.', 'PRICE_ACTION_INVALID', 400);
        }

        $storeId = trim((string) $request->input('store_id', ''));
        if ($storeId === '' || ! $this->stores->canAccess($request->user('web'), $storeId)) {
            return $this->error('Store tidak diizinkan.', 'STORE_FORBIDDEN', 403);
        }

        return $this->error(
            'Konektor harga marketplace belum aktif. Harga katalog terakhir tetap dapat dilihat pada daftar produk.',
            'PROVIDER_CONNECTOR_INACTIVE',
            503,
        );
    }

    private function error(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'error_code' => $code], $status)
            ->header('Cache-Control', 'no-store, private');
    }
}
