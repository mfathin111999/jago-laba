<?php

namespace App\Http\Controllers;

use App\Authorization\StoreContext;
use App\Exceptions\MarketplaceOAuthException;
use App\Pilots\Marketplace\MarketplaceBrandingService;
use App\Pilots\Marketplace\MarketplaceIntegrationHealthService;
use App\Pilots\Marketplace\MarketplaceOAuthService;
use App\Pilots\Marketplace\MarketplaceStoreReadService;
use App\Pilots\Marketplace\MarketplaceSyncStatusService;
use App\Policies\RolePolicy;
use DomainException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** The Marketplace "Integrasi" page: connection state, source health, sync queue and company branding. */
final class MarketplaceIntegrationsPageController
{
    public function __construct(
        private readonly MarketplaceStoreReadService $stores,
        private readonly MarketplaceIntegrationHealthService $health,
        private readonly MarketplaceSyncStatusService $syncStatus,
        private readonly MarketplaceOAuthService $oauth,
        private readonly MarketplaceBrandingService $branding,
        private readonly StoreContext $storeAccess,
        private readonly RolePolicy $policy,
    ) {}

    public function __invoke(Request $request): Response
    {
        $companyKey = (string) $request->attributes->get('company_key');
        $user = $request->user('web');
        $overview = $this->stores->overview($companyKey);
        $requested = trim((string) ($request->query('store_id') ?: $request->query('store', '')));
        $storeId = $requested !== '' ? $requested : (string) ($overview['stores'][0]['id'] ?? '');

        abort_if($storeId !== '' && ! $this->storeAccess->canAccess($user, $storeId), 403, 'Store tidak diizinkan.');

        $canManage = $this->policy->allows($user, 'integration.manage');
        $health = null;
        $healthError = null;
        if ($storeId !== '') {
            try {
                $health = $this->health->forStore($companyKey, $storeId, $canManage);
            } catch (DomainException $exception) {
                $healthError = $exception->getMessage();
            }
        }

        $brand = $this->branding->read($companyKey);
        $oauth = $this->oauth->configurationStatus();

        return Inertia::render('Marketplace/Integrations', [
            'stores' => $overview['stores'],
            'scope' => ['company_key' => $companyKey, 'store_id' => $storeId !== '' ? $storeId : null],
            'health' => $health,
            'health_error' => $healthError,
            'sync' => $storeId !== '' ? $this->syncStatus->status($companyKey, $storeId) : null,
            'platforms' => array_values($oauth['platforms']),
            'oauth_mode' => (string) $oauth['mode'],
            'callback_url' => (string) $oauth['legacy_callback_url'],
            'oauth' => $this->oauthReturn($request, $canManage, $companyKey),
            'branding' => [
                'has_logo' => ! empty($brand['logo_mime']),
                'logo_size' => (int) $brand['logo_size'],
                'logo_fit' => (string) $brand['logo_fit'],
                'updated_at' => $brand['updated_at'] ?? null,
            ],
            'can_manage' => $canManage,
            'can_sync' => $this->policy->allows($user, 'sync.trigger'),
            'can_brand' => $this->policy->allows($user, 'settings.manage'),
        ]);
    }

    /**
     * What the provider callback left in the query string: a result banner, and the shops to choose from.
     *
     * @return array<string, mixed>|null
     */
    private function oauthReturn(Request $request, bool $canManage, string $companyKey): ?array
    {
        $status = (string) $request->query('oauth_status', '');
        if ($status === '') {
            return null;
        }

        $result = ['status' => $status, 'message' => (string) $request->query('oauth_message', ''), 'pending_id' => null, 'platform' => null, 'shops' => []];
        $pendingId = (string) $request->query('pending', '');
        if ($status === 'choose' && $pendingId !== '' && $canManage) {
            try {
                $pending = $this->oauth->pending($request->user('web'), $companyKey, $pendingId);
                $result['pending_id'] = $pendingId;
                $result['platform'] = $pending['platform'];
                $result['shops'] = array_map(
                    static fn (array $shop): array => ['id' => (string) ($shop['id'] ?? ''), 'name' => (string) ($shop['name'] ?? $shop['id'] ?? '')],
                    $pending['shops'],
                );
            } catch (MarketplaceOAuthException) {
                $result['status'] = 'error';
                $result['message'] = 'Sesi pemilihan toko sudah kedaluwarsa. Hubungkan ulang.';
            } catch (Throwable) {
                $result['status'] = 'error';
                $result['message'] = 'Daftar toko belum dapat dimuat.';
            }
        }

        return $result;
    }
}
