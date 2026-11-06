<?php

namespace App\Services\Marketplace;

use App\Auth\AuthenticatedUser;
use App\Services\Marketplace\OAuth\MarketplaceOAuthProviderFactory;

/**
 * Door to the features that talk to a real marketplace (fulfillment, shipping documents, price editor, order finance).
 * In live mode a controller keeps its own validation and then runs the service as the signed-in user inside the
 * active company.
 */
final class LiveGateway
{
    public function __construct(
        private readonly MarketplaceScope $scope,
        private readonly MarketplaceOAuthProviderFactory $providers,
    ) {}

    public function enabled(): bool
    {
        return $this->providers->live();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(AuthenticatedUser $user, string $companyKey, callable $callback): mixed
    {
        return $this->scope->run($user, $companyKey, $callback);
    }
}
