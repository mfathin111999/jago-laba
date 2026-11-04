<?php

namespace App\Http\Controllers;

use App\Authorization\StoreContext;
use App\Marketplace\LiveGateway;
use App\Pilots\Marketplace\MarketplaceAutoSyncService;
use App\Pilots\Marketplace\MarketplaceStoreReadService;
use App\Pilots\Marketplace\MarketplaceSyncStatusService;
use App\Policies\RolePolicy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The Marketplace "Sinkronisasi" page: manual runs, the queue, automatic schedules and the price-editor boundary. */
final class MarketplaceSyncPageController
{
    public function __construct(
        private readonly MarketplaceStoreReadService $stores,
        private readonly MarketplaceSyncStatusService $syncStatus,
        private readonly MarketplaceAutoSyncService $autoSync,
        private readonly StoreContext $storeAccess,
        private readonly RolePolicy $policy,
        private readonly LiveGateway $live,
    ) {}

    public function __invoke(Request $request): Response
    {
        $companyKey = (string) $request->attributes->get('company_key');
        $user = $request->user('web');
        $overview = $this->stores->overview($companyKey);
        $requested = trim((string) ($request->query('store_id') ?: $request->query('store', '')));
        $storeId = $requested !== '' ? $requested : (string) ($this->syncStatus->previewStores($companyKey)[0]['id'] ?? $overview['stores'][0]['id'] ?? '');

        abort_if($storeId !== '' && ! $this->storeAccess->canAccess($user, $storeId), 403, 'Store tidak diizinkan.');

        $preview = $storeId !== '' ? ($this->syncStatus->previewStores($companyKey, $storeId)[0] ?? null) : null;

        return Inertia::render('Marketplace/Sync', [
            'stores' => $overview['stores'],
            'scope' => ['company_key' => $companyKey, 'store_id' => $storeId !== '' ? $storeId : null],
            'preview' => $preview,
            'status' => $storeId !== '' ? $this->syncStatus->status($companyKey, $storeId) : null,
            'auto' => $storeId !== '' ? array_values($this->autoSync->states($companyKey, $storeId)) : [],
            'scheduler_available' => $this->autoSync->available(),
            'live' => $this->live->enabled(),
            'can_sync' => $this->policy->allows($user, 'sync.trigger'),
            'can_manage' => $this->policy->allows($user, 'integration.manage'),
        ]);
    }
}
