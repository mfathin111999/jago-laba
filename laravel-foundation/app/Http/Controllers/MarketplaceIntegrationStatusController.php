<?php

namespace App\Http\Controllers;

use App\Authorization\StoreContext;
use App\Pilots\Marketplace\MarketplaceIntegrationHealthService;
use App\Pilots\Marketplace\MarketplaceSyncDispatchService;
use App\Pilots\Marketplace\MarketplaceSyncStatusService;
use App\Policies\RolePolicy;
use App\Security\CsrfTokenValidator;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Legacy-compatible /api/marketplace-integration-status.php.
 * GET returns per-resource health for a store; POST action=retry re-enqueues a failed source (administrator only).
 */
final class MarketplaceIntegrationStatusController
{
    public function __construct(
        private readonly MarketplaceIntegrationHealthService $health,
        private readonly MarketplaceSyncDispatchService $dispatcher,
        private readonly MarketplaceSyncStatusService $syncStatus,
        private readonly DatabaseManager $database,
        private readonly StoreContext $stores,
        private readonly RolePolicy $policy,
        private readonly CsrfTokenValidator $csrf,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user('web');
        $companyKey = (string) $request->attributes->get('company_key');
        $storeId = trim((string) $request->input('store_id', ''));

        try {
            if ($storeId === '' || ! $this->stores->canAccess($user, $storeId)) {
                throw new DomainException('Store tidak diizinkan.');
            }
            if ($request->isMethod('POST')) {
                if (! $this->csrf->valid($request)) {
                    return $this->json(['success' => false, 'message' => 'Token CSRF tidak valid.', 'code' => 'CSRF_INVALID'], 419);
                }
                if (! $this->policy->allows($user, 'integration.manage')) {
                    throw new DomainException('Administrator diperlukan.');
                }
                if ($request->input('action') !== 'retry') {
                    throw new InvalidArgumentException('Aksi tidak dikenal.');
                }
                $this->retry($companyKey, $storeId, (int) $request->input('job_id', 0));
            } elseif (! $request->isMethod('GET')) {
                throw new InvalidArgumentException('Metode tidak didukung.');
            }

            return $this->json(['success' => true, 'data' => $this->health->forStore($companyKey, $storeId, $this->policy->allows($user, 'integration.manage'))]);
        } catch (DomainException $exception) {
            return $this->json(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (InvalidArgumentException $exception) {
            return $this->json(['success' => false, 'message' => $exception->getMessage()], 400);
        } catch (Throwable $exception) {
            Log::error('Marketplace integration status failed.', ['exception' => $exception::class, 'message' => $exception->getMessage()]);

            return $this->json(['success' => false, 'message' => 'Status integrasi belum dapat dimuat. Coba kembali.'], 400);
        }
    }

    private function retry(string $companyKey, string $storeId, int $jobId): void
    {
        if ($this->syncStatus->hasPendingJob($companyKey, $storeId)) {
            throw new DomainException('Sinkronisasi toko masih berjalan. Coba ulang sumber yang gagal setelah proses selesai.');
        }
        $failed = $this->database->connection()->table('marketplace_sync_jobs')
            ->where('id', $jobId)->where('company_key', $companyKey)->where('store_id', $storeId)->where('status', 'failed')->exists();
        if (! $failed) {
            throw new DomainException('Proses gagal tidak ditemukan atau sudah dicoba ulang.');
        }

        $this->dispatcher->dispatchForStore($companyKey, $storeId, 'retry-'.$jobId);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'no-store, private');
    }
}
