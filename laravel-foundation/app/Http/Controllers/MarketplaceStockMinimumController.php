<?php

namespace App\Http\Controllers;

use App\Auth\LegacyUser;
use App\Authorization\StoreContext;
use App\Pilots\Marketplace\MarketplaceStockMinimumService;
use App\Security\CsrfTokenValidator;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Legacy-compatible POST /api/marketplace-stock.php: sets a minimum stock threshold (administrator only).
 */
final class MarketplaceStockMinimumController
{
    public function __construct(
        private readonly MarketplaceStockMinimumService $service,
        private readonly StoreContext $stores,
        private readonly CsrfTokenValidator $csrf,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user('web');

        try {
            if (! $user instanceof LegacyUser) {
                throw new DomainException('Autentikasi diperlukan.');
            }
            if (! $this->csrf->valid($request)) {
                return $this->json(['success' => false, 'message' => 'Token CSRF tidak valid.', 'code' => 'CSRF_INVALID'], 419);
            }
            $storeId = trim((string) $request->input('store_id', ''));
            if ($storeId === '' || ! $this->stores->canAccess($user, $storeId)) {
                throw new DomainException('Store tidak diizinkan.');
            }

            $this->service->setMinimum(
                $user,
                (string) $request->attributes->get('company_key'),
                $storeId,
                (string) $request->input('product_id', ''),
                (string) $request->input('variant_id', ''),
                (string) $request->input('warehouse_id', ''),
                (string) $request->input('minimum', ''),
            );

            return $this->json(['success' => true, 'message' => 'Minimum stok tersimpan. Stok marketplace dan HPP tidak berubah.']);
        } catch (DomainException $exception) {
            return $this->json(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (InvalidArgumentException $exception) {
            return $this->json(['success' => false, 'message' => $exception->getMessage()], 400);
        } catch (Throwable $exception) {
            Log::error('Marketplace stock minimum failed.', ['exception' => $exception::class, 'message' => $exception->getMessage()]);

            return $this->json(['success' => false, 'message' => 'Minimum stok belum tersimpan. Coba kembali.'], 400);
        }
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'no-store, private');
    }
}
