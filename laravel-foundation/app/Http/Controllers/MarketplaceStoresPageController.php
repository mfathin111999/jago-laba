<?php

namespace App\Http\Controllers;

use App\Services\Marketplace\MarketplaceStoreDeletionService;
use App\Services\Marketplace\MarketplaceStoreReadService;
use App\Policies\RolePolicy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The Marketplace "Toko" page: active and archived stores, with the actions the user is allowed to take. */
final class MarketplaceStoresPageController
{
    public function __construct(
        private readonly MarketplaceStoreReadService $stores,
        private readonly MarketplaceStoreDeletionService $deletion,
        private readonly RolePolicy $policy,
    ) {}

    public function __invoke(Request $request): Response
    {
        $companyKey = $request->companyKey();
        $user = $request->user('web');
        $canDelete = $this->policy->allows($user, 'store.delete');

        return Inertia::render('Marketplace/Stores', $this->stores->overview($companyKey) + [
            'scope' => ['company_key' => $companyKey, 'store_id' => null],
            'can_manage' => $this->policy->allows($user, 'integration.manage'),
            'can_delete' => $canDelete,
            'deletions' => $canDelete && $this->deletion->ready() ? $this->deletion->recentStatuses($companyKey) : [],
            'deletion_ready' => $this->deletion->ready(),
        ]);
    }
}
