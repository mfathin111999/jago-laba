<?php

namespace App\Services\Marketplace\OAuth;

interface MarketplaceOAuthProvider
{
    public function platform(): string;

    /** @return array<string, mixed> */
    public function authorizationUrl(string $state, string $redirectUri, ?string $scenario = null): string;

    /** @return array{access_token:string,refresh_token:string,token_expires_at:string,refresh_token_expires_at:string,granted_scopes:list<string>,shops:list<array<string,mixed>>} */
    public function exchangeCode(string $code, array $callback): array;

    /** @return array{access_token:string,refresh_token:string,token_expires_at:string,refresh_token_expires_at:string,granted_scopes:list<string>,shops:list<array<string,mixed>>} */
    public function refreshToken(string $refreshToken, ?string $externalStoreId = null, ?string $shopCipher = null): array;
}
