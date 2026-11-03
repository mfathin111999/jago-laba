<?php

namespace App\Http\Controllers;

use App\Authorization\StoreContext;
use App\Pilots\Marketplace\MarketplaceSyncDispatchService;
use App\Pilots\Marketplace\MarketplaceSyncStatusService;
use App\Security\CsrfTokenValidator;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Legacy-compatible GET|POST /api/marketplace-sync.php (administrator only).
 *
 * Actions map onto the database queue: start/background enqueue a run, step/status report
 * progress (the worker advances jobs), preview lists eligible stores. Catalog sync needs a
 * provider connector and is answered with an explicit unavailable message.
 */
final class MarketplaceSyncApiController
{
    private const MUTATING = ['start', 'step', 'background', 'catalog'];

    public function __construct(
        private readonly MarketplaceSyncDispatchService $dispatcher,
        private readonly MarketplaceSyncStatusService $status,
        private readonly StoreContext $stores,
        private readonly CsrfTokenValidator $csrf,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $companyKey = (string) $request->attributes->get('company_key');
        $action = (string) ($request->input('action') ?: 'status');
        $storeId = trim((string) $request->input('store_id', ''));

        try {
            if (in_array($action, self::MUTATING, true)) {
                if (! $request->isMethod('POST')) {
                    throw new InvalidArgumentException('Gunakan POST untuk menjalankan sinkronisasi.');
                }
                if (! $this->csrf->valid($request)) {
                    return $this->json(['success' => false, 'message' => 'Token CSRF tidak valid.', 'code' => 'CSRF_INVALID'], 419);
                }
            }

            if ($action === 'preview') {
                if ($storeId !== '' && ! $this->stores->canAccess($request->user('web'), $storeId)) {
                    throw new DomainException('Store tidak diizinkan.');
                }

                return $this->json(['success' => true, 'data' => ['stores' => $this->status->previewStores($companyKey, $storeId)]]);
            }

            if (! in_array($action, ['start', 'step', 'background', 'status', 'catalog', 'catalog_status'], true)) {
                throw new InvalidArgumentException('Aksi tidak dikenal.');
            }
            if ($storeId === '' || ! $this->stores->canAccess($request->user('web'), $storeId)) {
                throw new DomainException('Store tidak diizinkan.');
            }
            if (in_array($action, ['catalog', 'catalog_status'], true)) {
                throw new InvalidArgumentException('Sinkronisasi katalog belum tersedia: konektor resmi marketplace belum aktif.');
            }

            if (in_array($action, ['start', 'background'], true) && ! $this->status->hasPendingJob($companyKey, $storeId)) {
                $this->dispatcher->dispatchForStore($companyKey, $storeId, 'browser');
            }

            return $this->json(['success' => true, 'data' => $this->status->status($companyKey, $storeId)]);
        } catch (DomainException $exception) {
            return $this->json(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (InvalidArgumentException $exception) {
            return $this->json(['success' => false, 'message' => $exception->getMessage()], 400);
        } catch (Throwable $exception) {
            Log::error('Marketplace sync endpoint failed.', ['exception' => $exception::class, 'message' => $exception->getMessage()]);

            return $this->json(['success' => false, 'message' => 'Sinkronisasi belum dapat diproses. Periksa koneksi, schema worker, dan konfigurasi API.'], 400);
        }
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'no-store, private');
    }
}
