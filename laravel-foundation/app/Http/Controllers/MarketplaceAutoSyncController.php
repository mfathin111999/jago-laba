<?php

namespace App\Http\Controllers;

use App\Authorization\StoreContext;
use App\Pilots\Marketplace\MarketplaceAutoSyncService;
use App\Policies\RolePolicy;
use App\Security\CsrfTokenValidator;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Legacy-compatible /api/marketplace-auto-sync.php (read states / administrator toggles a mode)
 * and POST /api/marketplace-browser-pulse.php (opportunistic tick while a browser tab is open).
 */
final class MarketplaceAutoSyncController
{
    private const PULSE_LIMIT = 5;

    public function __construct(
        private readonly MarketplaceAutoSyncService $service,
        private readonly StoreContext $stores,
        private readonly RolePolicy $policy,
        private readonly CsrfTokenValidator $csrf,
    ) {}

    public function states(Request $request): JsonResponse
    {
        $companyKey = (string) $request->attributes->get('company_key');
        $storeId = trim((string) $request->input('store_id', ''));

        try {
            if ($storeId === '' || ! $this->stores->canAccess($request->user('web'), $storeId)) {
                throw new DomainException('Store tidak diizinkan.');
            }
            if ($request->isMethod('POST')) {
                if (! $this->csrf->valid($request)) {
                    return $this->json(['success' => false, 'message' => 'Token CSRF tidak valid.', 'code' => 'CSRF_INVALID'], 419);
                }
                if (! $this->policy->allows($request->user('web'), 'integration.manage')) {
                    throw new DomainException('Hanya administrator dapat mengubah auto sync.');
                }
                if (! in_array($request->input('enabled'), ['0', '1', 0, 1, false, true], true)) {
                    throw new InvalidArgumentException('Nilai aktif tidak valid.');
                }
                $enabled = in_array($request->input('enabled'), ['1', 1, true], true);
                $states = $this->service->set($companyKey, $storeId, (string) $request->input('mode', ''), $enabled);
            } else {
                $states = $this->service->states($companyKey, $storeId);
            }

            return $this->json(['success' => true, 'states' => $states, 'scheduler_available' => $this->service->available()]);
        } catch (DomainException $exception) {
            return $this->json(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (InvalidArgumentException $exception) {
            return $this->json(['success' => false, 'message' => $exception->getMessage()], 400);
        } catch (Throwable $exception) {
            Log::error('Marketplace auto sync failed.', ['exception' => $exception::class, 'message' => $exception->getMessage()]);

            return $this->json(['success' => false, 'message' => 'Auto sync belum dapat diproses. Periksa layanan server.'], 400);
        }
    }

    public function pulse(Request $request): JsonResponse
    {
        if (! $this->csrf->valid($request)) {
            return $this->json(['success' => false, 'message' => 'Token CSRF tidak valid.', 'code' => 'CSRF_INVALID'], 419);
        }

        try {
            $this->service->tick((string) $request->attributes->get('company_key'), null, self::PULSE_LIMIT);

            return $this->json(['success' => true, 'mode' => 'browser']);
        } catch (Throwable $exception) {
            Log::error('Marketplace browser pulse failed.', ['exception' => $exception::class, 'message' => $exception->getMessage()]);

            return $this->json(['success' => false, 'message' => 'AutoSync belum dapat berjalan. Muat ulang halaman atau periksa koneksi toko.'], 500);
        }
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'no-store, private');
    }
}
