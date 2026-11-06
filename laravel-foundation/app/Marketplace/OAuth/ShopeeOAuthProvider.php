<?php

namespace App\Marketplace\OAuth;

use App\Exceptions\MarketplaceIntegrationException;
use App\Services\Marketplace\Http\Signing\ShopeeSigner;

/** Shopee Open Platform v2 seller authorization: code exchange, refresh and shop identity check. */
final class ShopeeOAuthProvider extends PartnerOAuthProvider
{
    public function platform(): string
    {
        return 'Shopee';
    }

    public function configurationMissing(): array
    {
        $missing = [];
        foreach (['partner_id', 'partner_key'] as $key) {
            if (trim((string) ($this->config[$key] ?? '')) === '') {
                $missing[] = 'JL_SHOPEE_'.strtoupper($key);
            }
        }
        if (trim((string) ($this->config['auth_url'] ?? '')) === '') {
            $missing[] = 'JL_SHOPEE_AUTH_URL';
        }
        if (trim((string) ($this->config['api_base_url'] ?? '')) === '') {
            $missing[] = 'JL_SHOPEE_API_BASE_URL';
        }
        // Live and sandbox use fixed host pairs; a mixed or unknown pair is treated as not configured.
        $mode = (string) ($this->config['region'] ?? 'live');
        $hosts = $mode === 'sandbox' ? ['open.sandbox.test-stable.shopee.com', 'openplatform.sandbox.test-stable.shopee.sg'] : ['open.shopee.com', 'partner.shopeemobile.com'];
        $auth = (string) ($this->config['auth_url'] ?? '');
        $api = (string) ($this->config['api_base_url'] ?? '');
        if (! in_array($mode, ['live', 'sandbox'], true)
            || parse_url($auth, PHP_URL_HOST) !== $hosts[0] || parse_url($api, PHP_URL_HOST) !== $hosts[1]
            || parse_url($auth, PHP_URL_SCHEME) !== 'https' || parse_url($api, PHP_URL_SCHEME) !== 'https') {
            $missing[] = 'JL_SHOPEE_ENVIRONMENT_URLS';
        }

        return array_values(array_unique($missing));
    }

    protected function buildAuthorizationUrl(string $state, string $redirectUri): string
    {
        $this->assertConfigured();

        return (string) $this->config['auth_url'].'?'.http_build_query([
            'partner_id' => (string) $this->config['partner_id'],
            'response_type' => 'code',
            'auth_type' => 'seller',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    protected function requestTokens(string $code, array $callback): array
    {
        $this->assertConfigured();
        $shopId = trim((string) ($callback['shop_id'] ?? ''));
        $body = [
            'code' => $code,
            'shop_id' => $this->numericId($shopId),
            'partner_id' => $this->numericId((string) $this->config['partner_id']),
        ];
        if ($shopId === '') {
            throw new MarketplaceIntegrationException('Respons otorisasi Shopee tidak lengkap.', 'SHOPEE_MISSING_SHOP');
        }

        $payload = $this->payload($this->http->request('POST', $this->publicUrl('/api/v2/auth/token/get'), [], $body));
        $accessToken = trim((string) ($payload['access_token'] ?? ''));
        $refreshToken = trim((string) ($payload['refresh_token'] ?? ''));
        if ($accessToken === '' || $refreshToken === '') {
            throw new MarketplaceIntegrationException('Token Shopee tidak diterima lengkap.', 'SHOPEE_TOKEN_INCOMPLETE');
        }

        return $this->tokenSet($payload, $accessToken, $refreshToken, $this->verifyShop($accessToken, $shopId));
    }

    protected function requestRefresh(string $refreshToken, ?string $externalStoreId, ?string $shopCipher): array
    {
        $this->assertConfigured();
        $shopId = trim((string) $externalStoreId);
        if ($shopId === '') {
            throw new MarketplaceIntegrationException('Identitas toko Shopee tidak tersedia.', 'SHOPEE_MISSING_SHOP');
        }
        $body = [
            'refresh_token' => $refreshToken,
            'shop_id' => $this->numericId($shopId),
            'partner_id' => $this->numericId((string) $this->config['partner_id']),
        ];
        $payload = $this->payload($this->http->request('POST', $this->publicUrl('/api/v2/auth/access_token/get'), [], $body));
        $accessToken = trim((string) ($payload['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new MarketplaceIntegrationException('Token Shopee baru tidak diterima.', 'SHOPEE_REFRESH_INCOMPLETE');
        }

        return $this->tokenSet($payload, $accessToken, trim((string) ($payload['refresh_token'] ?? '')) ?: $refreshToken, $this->verifyShop($accessToken, $shopId));
    }

    /** @param array<string, mixed> $payload */
    private function tokenSet(array $payload, string $accessToken, string $refreshToken, array $shops): array
    {
        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_expires_at' => $this->expiryFromSeconds($payload['expire_in'] ?? null),
            'refresh_token_expires_at' => $this->expiryFromSeconds($payload['refresh_token_expire_in'] ?? null),
            'granted_scopes' => is_array($payload['granted_scopes'] ?? null) ? $payload['granted_scopes'] : [],
            'shops' => $shops,
        ];
    }

    /** @return list<array{id: string, name: string, cipher: null, region: null}> */
    private function verifyShop(string $accessToken, string $shopId): array
    {
        $path = '/api/v2/shop/get_shop_info';
        $timestamp = now()->timestamp;
        $query = [
            'partner_id' => (string) $this->config['partner_id'],
            'timestamp' => $timestamp,
            'access_token' => $accessToken,
            'shop_id' => $shopId,
            'sign' => $this->sign($path, $timestamp, $accessToken, $shopId),
        ];
        $payload = $this->payload($this->http->request('GET', rtrim((string) $this->config['api_base_url'], '/').$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)));
        $returnedId = trim((string) ($payload['shop_id'] ?? $shopId));
        $name = trim((string) ($payload['shop_name'] ?? $payload['name'] ?? ''));
        if ($returnedId === '' || $returnedId !== $shopId || $name === '') {
            throw new MarketplaceIntegrationException('Identitas toko Shopee tidak dapat diverifikasi.', 'SHOPEE_IDENTITY_INCOMPLETE');
        }

        return [['id' => $returnedId, 'name' => $name, 'cipher' => null, 'region' => null]];
    }

    /**
     * Shopee answers 200 with an `error` field when it refuses; the useful part sits in `response` when present.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function payload(array $response): array
    {
        if (trim((string) ($response['error'] ?? '')) !== '') {
            throw new MarketplaceIntegrationException('Shopee menolak otorisasi atau izin API tidak cukup.', 'SHOPEE_API_REJECTED');
        }

        return is_array($response['response'] ?? null) ? $response['response'] : $response;
    }

    private function numericId(string $value): int
    {
        if (! ctype_digit($value) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new MarketplaceIntegrationException('Identitas Partner atau toko Shopee tidak valid.', 'SHOPEE_ID_INVALID');
        }

        return (int) $value;
    }

    /** URL for the calls that are signed with the partner key only (no shop token yet). */
    private function publicUrl(string $path): string
    {
        $timestamp = now()->timestamp;

        return rtrim((string) $this->config['api_base_url'], '/').$path.'?'.http_build_query([
            'partner_id' => (string) $this->config['partner_id'],
            'timestamp' => $timestamp,
            'sign' => $this->sign($path, $timestamp),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function sign(string $path, int $timestamp, ?string $accessToken = null, ?string $shopId = null): string
    {
        return ShopeeSigner::sign((string) $this->config['partner_id'], (string) $this->config['partner_key'], $path, $timestamp, $accessToken, $shopId);
    }
}
