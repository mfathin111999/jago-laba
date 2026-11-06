<?php

namespace App\Http\Controllers;

use App\Authorization\StoreContext;
use App\Marketplace\LiveGateway;
use App\Security\CsrfTokenValidator;
use App\Services\Marketplace\OrderReadService;
use App\Services\Marketplace\Sync\SyncService;
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
        private readonly LiveGateway $live,
        private readonly SyncService $sync,
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
            $detail = $this->orders->detail($request->companyKey(), $storeId, $orderId);
            if ($detail === null) {
                throw new DomainException('Pesanan tidak ditemukan.');
            }

            if ($this->live->enabled()) {
                return $this->liveFinance($request, $storeId, $orderId, $detail);
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

    /**
     * Live mode: refresh the order's finance from the Finance API with the legacy engine (skipped when it was refreshed in
     * the last 30 seconds, like the legacy endpoint), then answer with the order's current presentation.
     *
     * @param  array<string, mixed>  $detail
     */
    private function liveFinance(Request $request, string $storeId, string $orderId, array $detail): JsonResponse
    {
        $companyKey = $request->companyKey();
        try {
            $snapshot = (array) ($detail['presentation'] ?? []);
            $fresh = ! empty($snapshot['finance_updated_at']) && strtotime((string) $snapshot['finance_updated_at']) > time() - 30;
            $synced = $fresh || $this->live->run($request->user('web'), $companyKey, fn (): bool => $this->sync->with(orderAutomation: true)->refreshOrderFinance($storeId, $orderId));
            $current = $this->orders->detail($companyKey, $storeId, $orderId);

            return $this->json([
                'success' => true,
                'synced' => (bool) $synced,
                'presentation' => $current['presentation'] ?? $snapshot,
                'message' => $synced ? 'Data Finance API diperbarui.' : 'Marketplace belum menyediakan data keuangan pesanan ini.',
            ]);
        } catch (Throwable $e) {
            Log::error('Marketplace order finance (live) failed.', ['exception' => $e::class, 'message' => $e->getMessage()]);

            return $this->json([
                'success' => false,
                'message' => $e instanceof DomainException ? $e->getMessage() : 'Finance API belum dapat diperbarui. Periksa izin seller.finance.info atau coba lagi setelah batas API mereda.',
                'error_code' => $e instanceof \MarketplaceIntegrationException ? $e->errorCode : null,
            ], $e instanceof DomainException ? 403 : 400);
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
