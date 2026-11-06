<?php

namespace App\Marketplace;

use App\Auth\LegacyUser;
use App\Marketplace\OAuth\MarketplaceOAuthProviderFactory;
use App\Services\Marketplace\MarketplaceScope;
use PDO;

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
        private readonly LegacyExecutionContext $context,
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
    public function run(LegacyUser $user, string $companyKey, callable $callback): mixed
    {
        return $this->scope->run($user, $companyKey, $callback);
    }

    /**
     * For the services that are still legacy classes (the sync engine): same scope, plus the legacy runtime and a PDO.
     *
     * @template T
     *
     * @param  list<string>  $services  legacy service files (relative to app/Modules/Marketplace/Services) to load first
     * @param  callable(PDO): T  $callback
     * @return T
     */
    public function runLegacy(LegacyUser $user, string $companyKey, array $services, callable $callback): mixed
    {
        LegacyRuntime::prepare();
        foreach ($services as $service) {
            LegacyRuntime::service($service);
        }

        return $this->context->run($user, $companyKey, $callback);
    }
}
