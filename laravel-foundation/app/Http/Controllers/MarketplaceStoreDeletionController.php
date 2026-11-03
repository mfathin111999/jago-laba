<?php

namespace App\Http\Controllers;

use App\Pilots\Marketplace\MarketplaceStoreDeletionService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Legacy-compatible endpoint for POST /api/marketplace-store-deletion.php.
 * Actions: list, preview, status, request, resume. Owner-only via the store.delete role action.
 */
final class MarketplaceStoreDeletionController
{
    private const INLINE_BATCHES = 3;

    public function __construct(private readonly MarketplaceStoreDeletionService $service) {}

    public function __invoke(Request $request): JsonResponse
    {
        $companyKey = (string) $request->attributes->get('company_key');
        $action = (string) $request->input('action', '');
        $storeId = trim((string) $request->input('store_id', ''));

        try {
            if (! $this->service->ready()) {
                throw new DomainException('Fitur menunggu migrasi penghapusan toko oleh administrator server.');
            }
            if ($storeId === '' && $action !== 'list') {
                throw new DomainException('Pilih toko.');
            }

            if ($action === 'status') {
                $this->advance($storeId, 1);
            }

            $data = match ($action) {
                'list' => $this->service->recentStatuses($companyKey),
                'preview' => $this->service->preview($companyKey, $storeId),
                'status' => $this->service->status($companyKey, $storeId),
                'request' => $this->service->request($request->user('web'), $companyKey, $storeId, (string) $request->input('confirmation', '')),
                'resume' => $this->service->status($companyKey, $storeId) ?? throw new DomainException('Proses penghapusan tidak ditemukan.'),
                default => throw new DomainException('Tindakan tidak tersedia.'),
            };

            if (in_array($action, ['request', 'resume'], true) && ($data['status'] ?? '') !== 'completed') {
                $this->advance($storeId, self::INLINE_BATCHES);
                $data = $this->service->status($companyKey, $storeId) ?? $data;
            }

            return response()->json(['success' => true, 'data' => $data]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 400);
        } catch (Throwable $exception) {
            Log::error('Store deletion failed.', ['exception' => $exception::class, 'message' => $exception->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Penghapusan belum selesai. Data lain tetap terlindungi; coba lagi atau periksa migrasi dan penyimpanan server.'], 500);
        }
    }

    /** Runs a few bounded batches; the scheduled worker finishes the rest. Failures are retried by the worker. */
    private function advance(string $storeId, int $batches): void
    {
        try {
            for ($i = 0; $i < $batches; $i++) {
                if ($this->service->step($storeId)) {
                    return;
                }
            }
        } catch (Throwable) {
        }
    }
}
