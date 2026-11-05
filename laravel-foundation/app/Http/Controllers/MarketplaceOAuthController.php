<?php

namespace App\Http\Controllers;

use App\Auth\LegacyUser;
use App\Authorization\CompanyContext;
use App\Authorization\StoreContext;
use App\Authorization\WorkspaceContext;
use App\Exceptions\MarketplaceOAuthException;
use App\Http\Responses\LegacyResponse;
use App\Pilots\Marketplace\MarketplaceOAuthService;
use App\Pilots\Marketplace\MarketplaceSyncDispatchService;
use App\Pilots\Marketplace\MarketplaceSyncStatusService;
use App\Policies\RolePolicy;
use App\Security\CsrfTokenValidator;
use App\Support\RequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

final class MarketplaceOAuthController
{
    public function __construct(
        private readonly MarketplaceOAuthService $oauth,
        private readonly CompanyContext $company,
        private readonly WorkspaceContext $workspace,
        private readonly CsrfTokenValidator $csrf,
        private readonly RolePolicy $policy,
        private readonly StoreContext $stores,
        private readonly MarketplaceSyncDispatchService $dispatcher,
        private readonly MarketplaceSyncStatusService $syncStatus,
    ) {}

    public function __invoke(Request $request): JsonResponse|RedirectResponse
    {
        $action = trim((string) ($request->route('oauth_action') ?: $request->query('action', $request->input('action', 'callback'))));
        $user = Auth::guard('web')->user();

        if (! $user instanceof LegacyUser) {
            return $this->error('Sesi login berakhir. Silakan login kembali.', 'AUTH_REQUIRED', 401, $request);
        }

        $companyKey = $this->company->key($user);
        if ($companyKey === null) {
            return $this->error('Company context tidak tersedia.', 'COMPANY_REQUIRED', 403, $request);
        }

        if (! $this->company->matchesRequest($request, $user)) {
            return $this->error('Company tidak diizinkan.', 'COMPANY_FORBIDDEN', 403, $request);
        }

        if (! $this->workspace->can($user, WorkspaceContext::MARKETPLACE)) {
            return $this->error('Workspace Marketplace tidak diizinkan.', 'WORKSPACE_FORBIDDEN', 403, $request);
        }

        if ($this->workspace->active($user) !== WorkspaceContext::MARKETPLACE) {
            return $this->error('Workspace Marketplace belum aktif.', 'WORKSPACE_NOT_ACTIVE', 403, $request);
        }

        if ($request->isMethod('POST') && ! $this->csrf->valid($request)) {
            return $this->error('Token CSRF tidak valid.', 'CSRF_INVALID', 403, $request);
        }

        // Connecting, refreshing and disconnecting stores are administrator actions (same as adding a store);
        // queueing a sync needs the sync permission. Reading the configuration status is open to every user.
        $required = match ($action) {
            'start', 'callback', 'pending', 'select', 'refresh', 'disconnect' => 'integration.manage',
            'sync' => 'sync.trigger',
            default => null,
        };
        if ($required !== null && ! $this->policy->allows($user, $required)) {
            return $this->error('Aksi tidak diizinkan.', 'ROLE_FORBIDDEN', 403, $request);
        }

        try {
            $result = match ($action) {
                'status' => ['success' => true, 'data' => $this->oauth->configurationStatus()],
                'start' => $this->start($request, $user, $companyKey),
                'callback' => $this->callback($request, $user, $companyKey),
                'pending' => ['success' => true, 'data' => $this->oauth->pending($user, $companyKey, (string) $request->query('pending_id', $request->input('pending_id', '')))],
                'select' => ['success' => true, 'data' => $this->oauth->selectPending($user, $companyKey, (string) $request->input('pending_id'), (string) $request->input('external_store_id'))],
                'refresh' => ['success' => true, 'data' => $this->oauth->refresh($user, $companyKey, (string) $request->input('store_id'))],
                'disconnect' => ['success' => true, 'data' => $this->oauth->disconnect($user, $companyKey, (string) $request->input('store_id'))],
                'sync' => $this->sync($request, $user, $companyKey),
                default => throw new MarketplaceOAuthException('Aksi OAuth tidak dikenali.', 'OAUTH_ACTION_UNKNOWN'),
            };

            if ($result === null) {
                return LegacyResponse::unavailable('oauth');
            }

            if ($action === 'callback' && ! $request->expectsJson()) {
                return $this->redirectCallback($result['data'] ?? [], null);
            }

            return $this->success($result, $request);
        } catch (MarketplaceOAuthException $exception) {
            if ($action === 'callback' && ! $request->expectsJson()) {
                return $this->redirectCallback([], $exception);
            }

            return $this->error($this->safeMessage($exception->errorCode), $exception->errorCode, $this->statusFor($exception->errorCode), $request);
        } catch (Throwable $exception) {
            Log::error('Marketplace OAuth sandbox boundary failed.', [
                'request_id' => $request->attributes->get('request_id', RequestId::current()),
                'exception' => $exception::class,
            ]);

            return $this->error('Koneksi toko belum dapat diproses.', 'CONNECTION_NOT_VERIFIED', 400, $request);
        }
    }

    /**
     * Queues one sync run for a verified store of the user's company. A run that is still pending is reused.
     *
     * @return array<string, mixed>
     */
    private function sync(Request $request, LegacyUser $user, string $companyKey): array
    {
        $storeId = trim((string) $request->input('store_id'));
        if ($storeId === '' || ! $this->stores->canAccess($user, $storeId)) {
            throw new MarketplaceOAuthException('Store tidak diizinkan.', 'STORE_FORBIDDEN');
        }

        if (! $this->syncStatus->hasPendingJob($companyKey, $storeId)) {
            $this->dispatcher->dispatchForStore($companyKey, $storeId, 'oauth');
        }

        $latest = $this->syncStatus->status($companyKey, $storeId)['jobs'][0] ?? null;

        return [
            'success' => true,
            'message' => 'Sinkronisasi toko masuk antrean dan menunggu worker.',
            'data' => ['store_id' => $storeId, 'job_id' => $latest['id'] ?? null, 'status' => $latest['status'] ?? 'queued'],
        ];
    }

    /** @return array<string, mixed> */
    private function start(Request $request, LegacyUser $user, string $companyKey): array
    {
        $this->oauth->assertOrigin($request->header('Origin'));

        return [
            'success' => true,
            'data' => $this->oauth->start(
                $user,
                $companyKey,
                (string) $request->input('platform'),
                (string) $request->input('return_path', ''),
                (string) $request->input('scenario', ''),
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function callback(Request $request, LegacyUser $user, string $companyKey): array
    {
        return [
            'success' => true,
            'data' => $this->oauth->callback($user, $companyKey, $request->query()),
        ];
    }

    /** @param array<string, mixed> $result */
    private function success(array $result, Request $request): JsonResponse
    {
        return response()->json($result)
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Request-Id', $this->requestId($request));
    }

    private function error(string $message, string $code, int $status, Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'code' => $code,
            'request_id' => $this->requestId($request),
        ], $status)->header('Cache-Control', 'no-store, private');
    }

    /** @param array<string, mixed> $data */
    private function redirectCallback(array $data, ?MarketplaceOAuthException $exception): RedirectResponse
    {
        $returnPath = $this->safeRedirectPath((string) ($data['return_path'] ?? config('jago.oauth.return_path', 'marketplace/integrations')));
        $query = [
            'oauth_status' => $exception === null ? (($data['status'] ?? 'success') === 'choose' ? 'choose' : 'success') : 'error',
        ];
        if ($exception !== null) {
            $query['oauth_message'] = $this->safeMessage($exception->errorCode);
            $query['oauth_code'] = $exception->errorCode;
        }
        if (($data['pending_id'] ?? '') !== '') {
            $query['pending'] = $data['pending_id'];
        }

        $separator = str_contains($returnPath, '?') ? '&' : '?';

        return redirect()->to('/'.ltrim($returnPath, '/').$separator.http_build_query($query), 303);
    }

    private function safeRedirectPath(string $path): string
    {
        $parsed = parse_url($path);
        if (($parsed['scheme'] ?? '') !== '' || ($parsed['host'] ?? '') !== '' || ! in_array($parsed['path'] ?? 'marketplace.php', ['marketplace.php', 'marketplace/integrations'], true)) {
            return (string) config('jago.oauth.return_path', 'marketplace/integrations');
        }

        return ltrim($path, '/');
    }

    private function requestId(Request $request): string
    {
        $id = (string) $request->attributes->get('request_id', '');
        if ($id === '') {
            $id = RequestId::current();
            $request->attributes->set('request_id', $id);
        }

        return $id;
    }

    private function statusFor(string $code): int
    {
        return match ($code) {
            'AUTH_REQUIRED' => 401,
            'STORE_FORBIDDEN', 'ROLE_FORBIDDEN' => 403,
            'STORE_BEING_DELETED' => 409,
            'PROVIDER_RATE_LIMITED' => 429,
            'PROVIDER_TIMEOUT' => 504,
            'OAUTH_STORAGE_UNAVAILABLE' => 503,
            'TOKEN_EXCHANGE_FAILED', 'REFRESH_TOKEN_FAILED' => 502,
            default => 400,
        };
    }

    private function safeMessage(string $code): string
    {
        return match ($code) {
            'AUTH_REQUIRED' => 'Sesi login berakhir. Silakan login kembali.',
            'CSRF_INVALID' => 'Token CSRF tidak valid.',
            'COMPANY_FORBIDDEN' => 'Company tidak diizinkan.',
            'STORE_FORBIDDEN' => 'Store tidak diizinkan.',
            'WORKSPACE_FORBIDDEN' => 'Workspace Marketplace tidak diizinkan.',
            'WORKSPACE_NOT_ACTIVE' => 'Workspace Marketplace belum aktif.',
            'INVALID_OAUTH_STATE', 'INVALID_OAUTH_PLATFORM' => 'Sesi otorisasi tidak valid atau sudah kedaluwarsa.',
            'OAUTH_ORIGIN_MISMATCH' => 'Domain sesi berbeda dari callback.',
            'AUTH_CANCELLED' => 'Otorisasi dibatalkan.',
            'TOKEN_EXCHANGE_FAILED', 'REFRESH_TOKEN_FAILED' => 'Pertukaran atau refresh token gagal.',
            'PROVIDER_TIMEOUT' => 'Provider sandbox tidak merespons tepat waktu.',
            'PROVIDER_RATE_LIMITED' => 'Provider sandbox sedang membatasi permintaan.',
            'STORE_OWNED_BY_OTHER_COMPANY' => 'Toko sudah terhubung ke perusahaan lain.',
            'STORE_BEING_DELETED' => 'Toko sedang atau sudah dihapus. Mulai otorisasi baru setelah penghapusan selesai.',
            'SHOP_IDENTITY_MISSING', 'SHOP_SELECTION_INVALID' => 'Identitas toko sandbox tidak dapat diverifikasi.',
            'REAUTHORIZE_REQUIRED' => 'Refresh token tidak tersedia; otorisasi ulang diperlukan.',
            'PLATFORM_UNSUPPORTED' => 'Platform Marketplace belum tersedia di registry sandbox.',
            'OAUTH_STORAGE_UNAVAILABLE' => 'Penyimpanan OAuth belum tersedia.',
            default => 'Koneksi toko belum dapat diproses.',
        };
    }
}
