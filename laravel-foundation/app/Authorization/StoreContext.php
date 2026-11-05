<?php

namespace App\Authorization;

use App\Models\Marketplace\MarketplaceStore;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

final class StoreContext
{
    public function __construct(
        private readonly CompanyContext $company,
    ) {}

    public function canAccess(?Authenticatable $user, string $storeId): bool
    {
        $storeId = trim($storeId);
        $companyKey = $this->company->key($user);

        if ($storeId === '' || $companyKey === null) {
            return false;
        }

        try {
            return MarketplaceStore::query()->forCompany($companyKey)->active()->whereKey($storeId)->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
