<?php

namespace App\Services\Marketplace\OAuth;

use App\Exceptions\MarketplaceIntegrationException;
use App\Services\Marketplace\Http\Signing\TikTokSigner;

/** TikTok Shop seller authorization: code exchange, refresh and the list of authorized shops. */
final class TikTokShopOAuthProvider extends PartnerOAuthProvider
{
    /** The token endpoints live on a separate host from the data API. */
    private const TOKEN_HOST = 'https://auth.tiktok-shops.com';

    public function platform(): string
    {
        return 'TikTok Shop';
    }

    public function configurationMissing(): array
    {
        $missing = [];
        foreach (['app_key', 'app_secret', 'service_id'] as $key) {
            if (trim((string) ($this->config[$key] ?? '')) === '') {
                $missing[] = 'JL_TIKTOK_SHOP_'.strtoupper($key);
            }
        }
        if (trim((string) ($this->config['auth_url'] ?? '')) === '') {
            $missing[] = 'JL_TIKTOK_SHOP_AUTH_URL';
        }
        if (trim((string) ($this->config['api_base_url'] ?? '')) === '') {
            $missing[] = 'JL_TIKTOK_SHOP_API_BASE_URL';
        }

        return array_values(array_unique($missing));
    }

    protected function buildAuthorizationUrl(string $state, string $redirectUri): string
    {
        $this->assertConfigured();

        return (string) $this->config['auth_url'].'?'.http_build_query(['service_id' => (string) $this->config['service_id'], 'state' => $state], '', '&', PHP_QUERY_RFC3986);
    }

    protected function requestTokens(string $code, array $callback): array
    {
        $this->assertConfigured();
        $response = $this->tokenCall('/api/v2/token/get', ['auth_code' => $code, 'grant_type' => 'authorized_code']);
        $payload = $this->sellerToken($response, 'TikTok Shop menolak otorisasi atau izin API tidak cukup.', 'TIKTOK_TOKEN_REJECTED');
        $accessToken = trim((string) ($payload['access_token'] ?? ''));
        $refreshToken = trim((string) ($payload['refresh_token'] ?? ''));
        if ($accessToken === '' || $refreshToken === '') {
            throw new MarketplaceIntegrationException('Token TikTok Shop tidak diterima lengkap.', 'TIKTOK_TOKEN_INCOMPLETE');
        }

        return $this->tokenSet($payload, $accessToken, $refreshToken);
    }

    protected function requestRefresh(string $refreshToken, ?string $externalStoreId, ?string $shopCipher): array
    {
        $this->assertConfigured();
        $response = $this->tokenCall('/api/v2/token/refresh', ['refresh_token' => $refreshToken, 'grant_type' => 'refresh_token']);
        $payload = $this->sellerToken($response, 'Token TikTok Shop tidak dapat diperbarui.', 'TIKTOK_REFRESH_REJECTED');
        $accessToken = trim((string) ($payload['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new MarketplaceIntegrationException('Token TikTok Shop baru tidak diterima.', 'TIKTOK_REFRESH_INCOMPLETE');
        }

        return $this->tokenSet($payload, $accessToken, trim((string) ($payload['refresh_token'] ?? '')) ?: $refreshToken);
    }

    /** @param array<string, mixed> $payload */
    private function tokenSet(array $payload, string $accessToken, string $refreshToken): array
    {
        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_expires_at' => $this->expiryFromSeconds($payload['access_token_expire_in'] ?? null),
            'refresh_token_expires_at' => $this->expiryFromSeconds($payload['refresh_token_expire_in'] ?? null),
            'granted_scopes' => $this->normalizeScopes($payload['granted_scopes'] ?? []),
            'shops' => $this->authorizedShops($accessToken),
        ];
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, mixed>
     */
    private function tokenCall(string $path, array $extra): array
    {
        $query = ['app_key' => (string) $this->config['app_key'], 'app_secret' => (string) $this->config['app_secret']] + $extra;

        return $this->http->request('GET', self::TOKEN_HOST.$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function sellerToken(array $response, string $rejectedMessage, string $rejectedCode): array
    {
        $payload = is_array($response['data'] ?? null) ? $response['data'] : $response;
        if ((int) ($response['code'] ?? 0) !== 0) {
            throw new MarketplaceIntegrationException($rejectedMessage, $rejectedCode);
        }
        if ((int) ($payload['user_type'] ?? -1) !== 0) {
            throw new MarketplaceIntegrationException('Token TikTok bukan token seller TikTok Shop.', 'TIKTOK_NOT_SELLER_TOKEN');
        }

        return $payload;
    }

    /** @return list<array{id: string, name: string, cipher: ?string, region: mixed}> */
    private function authorizedShops(string $accessToken): array
    {
        $path = '/authorization/202309/shops';
        $query = ['app_key' => (string) $this->config['app_key'], 'timestamp' => now()->timestamp];
        $query['sign'] = TikTokSigner::sign((string) $this->config['app_secret'], $path, $query);
        $response = $this->http->request('GET', rtrim((string) $this->config['api_base_url'], '/').$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), [
            'Content-Type' => 'application/json',
            'x-tts-access-token' => $accessToken,
        ]);
        if ((int) ($response['code'] ?? 0) !== 0) {
            throw new MarketplaceIntegrationException('TikTok Shop menolak akses toko atau izin API tidak cukup.', 'TIKTOK_SHOPS_REJECTED');
        }
        $shops = $response['data']['shops'] ?? $response['shops'] ?? [];
        if (! is_array($shops) || $shops === []) {
            throw new MarketplaceIntegrationException('Respons TikTok Shop tidak memuat toko terotorisasi.', 'TIKTOK_SHOPS_EMPTY');
        }
        $normalized = [];
        foreach ($shops as $shop) {
            if (! is_array($shop)) {
                continue;
            }
            $id = trim((string) ($shop['id'] ?? $shop['shop_id'] ?? ''));
            $name = trim((string) ($shop['name'] ?? $shop['shop_name'] ?? $shop['seller_name'] ?? ''));
            $cipher = trim((string) ($shop['cipher'] ?? $shop['shop_cipher'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }
            $normalized[] = ['id' => $id, 'name' => $name, 'cipher' => $cipher !== '' ? $cipher : null, 'region' => $shop['region'] ?? null];
        }
        if ($normalized === []) {
            throw new MarketplaceIntegrationException('Identitas toko TikTok Shop tidak dapat diverifikasi.', 'TIKTOK_IDENTITY_INCOMPLETE');
        }

        return $normalized;
    }

    /** @return list<string> */
    private function normalizeScopes(mixed $scopes): array
    {
        if (is_string($scopes)) {
            return array_values(array_filter(array_map('trim', explode(',', $scopes))));
        }

        return is_array($scopes) ? array_values(array_map('strval', $scopes)) : [];
    }
}
