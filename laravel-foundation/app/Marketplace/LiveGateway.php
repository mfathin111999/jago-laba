<?php

namespace App\Marketplace;

use App\Auth\LegacyUser;
use PDO;

/**
 * The single door to the legacy provider services (fulfillment, shipping documents, price editor, order finance).
 * In live mode a Laravel endpoint keeps its own validation and then runs the legacy service unchanged, inside the
 * legacy execution context (session-like company/role scope, the Laravel database connection).
 */
final class LiveGateway
{
    public function __construct(private readonly LegacyExecutionContext $context) {}

    public function enabled(): bool
    {
        return strtolower((string) config('jago.oauth.mode', 'sandbox')) === 'live';
    }

    /**
     * @template T
     *
     * @param  callable(PDO): T  $callback
     * @param  list<string>  $services  legacy service files (relative to app/Modules/Marketplace/Services) to load first
     * @return T
     */
    public function run(LegacyUser $user, string $companyKey, array $services, callable $callback): mixed
    {
        LegacyRuntime::prepare();
        foreach ($services as $service) {
            require_once dirname(__DIR__, 3).'/app/Modules/Marketplace/Services/'.$service;
        }

        return $this->context->run($user, $companyKey, $callback);
    }
}
