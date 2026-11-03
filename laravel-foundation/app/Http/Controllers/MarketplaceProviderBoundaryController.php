<?php

namespace App\Http\Controllers;

use App\Authorization\StoreContext;
use App\Pilots\Marketplace\OrderReadService;
use App\Security\CsrfTokenValidator;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Legacy endpoints whose full behaviour needs a live marketplace provider:
 *  - POST /api/marketplace-order-finance.php refreshes order finance from the Finance API.
 *  - POST /api/marketplace-partner-settings.php saves partner app credentials and tests them online.
 *
 * Provider calls stay disabled, so these keep the request contract (method, CSRF, role, scope) and answer
 * from local data only. Partner credentials are never accepted from the web; they belong in private server configuration.
 */
final class MarketplaceProviderBoundaryController
{
    private const PLATFORMS = ['Shopee', 'TikTok Shop'];

    public function __construct(
        private readonly OrderReadService $orders,
        private readonly StoreContext $stores,
        private readonly CsrfTokenValidator $csrf,
    ) {}

    public function orderFinance(Request $request): JsonResponse
    {
        if (! $this->csrf->valid($request)) {
            return $this->json(['success' => false, 'message' => 'Token CSRF tidak valid.', 'code' => 'CSRF_INVALID'], 419);
        }

        $storeId = trim((string) $request->input('store_id', ''));
        $orderId = trim((string) $request->input('order_id', ''));

        try {
            if ($storeId === '' || ! $this->stores->canAccess($request->user('web'), $storeId)) {
                throw new DomainException('Store tidak diizinkan.');
            }
            $detail = $this->orders->detail((string) $request->attributes->get('company_key'), $storeId, $orderId);
            if ($detail === null) {
                throw new DomainException('Pesanan tidak ditemukan.');
            }

            return $this->json([
                'success' => true,
                'synced' => false,
                'provider_call' => false,
                'presentation' => $detail['presentation'],
                'message' => 'Marketplace belum menyediakan data keuangan pesanan ini.',
            ]);
        } catch (DomainException $exception) {
            return $this->json(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (Throwable $exception) {
            Log::error('Marketplace order finance failed.', ['exception' => $exception::class, 'message' => $exception->getMessage()]);

            return $this->json(['success' => false, 'message' => 'Finance API belum dapat diperbarui. Periksa izin seller.finance.info atau coba lagi setelah batas API mereda.'], 400);
        }
    }

    public function partnerSettings(Request $request): JsonResponse
    {
        if (! $this->csrf->valid($request)) {
            return $this->json(['success' => false, 'message' => 'Token CSRF tidak valid.', 'code' => 'CSRF_INVALID'], 419);
        }
        if (! in_array((string) $request->input('platform', ''), self::PLATFORMS, true)) {
            return $this->json(['success' => false, 'message' => 'Platform tidak dikenal.', 'error_code' => 'PARTNER_PLATFORM_INVALID'], 400);
        }

        return $this->json([
            'success' => false,
            'message' => 'Kredensial partner dikelola lewat konfigurasi server yang privat, bukan formulir web. Hubungi administrator server.',
            'error_code' => 'PARTNER_SETTINGS_MANAGED_BY_SERVER',
        ], 503);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'no-store, private');
    }
}
