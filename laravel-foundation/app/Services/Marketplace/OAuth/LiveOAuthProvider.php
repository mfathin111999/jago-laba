<?php

namespace App\Services\Marketplace\OAuth;

/** A provider that talks to the real platform: it can say which settings are still missing and which environment it targets. */
interface LiveOAuthProvider extends MarketplaceOAuthProvider
{
    /** @return list<string> names of the environment variables that are still empty */
    public function configurationMissing(): array;

    /** @return array{fingerprint: string, environment: string} */
    public function context(): array;
}
