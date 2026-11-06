<?php

namespace App\Services\Marketplace\OAuth;

use App\Exceptions\MarketplaceOAuthException;
use Illuminate\Support\Str;

final class SandboxMarketplaceOAuthProvider implements MarketplaceOAuthProvider
{
    /** @var array<string, int> */
    private array $attempts = [];

    public function __construct(private readonly string $platform) {}

    public function platform(): string
    {
        return $this->platform;
    }

    public function authorizationUrl(string $state, string $redirectUri, ?string $scenario = null): string
    {
        return route('sandbox.marketplace.oauth.authorize', array_filter([
            'platform' => $this->platform,
            'state' => $state,
            'redirect_uri' => $redirectUri,
            'scenario' => $scenario,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    public function exchangeCode(string $code, array $callback): array
    {
        $scenario = $this->scenarioFromCode($code);
        $this->faultIfNeeded('exchange:'.$code, $scenario);

        if ($scenario === 'exchange_fail') {
            throw new MarketplaceOAuthException('Pertukaran token sandbox gagal.', 'TOKEN_EXCHANGE_FAILED');
        }

        return $this->tokenSet($scenario);
    }

    public function refreshToken(string $refreshToken, ?string $externalStoreId = null, ?string $shopCipher = null): array
    {
        $scenario = $this->scenarioFromToken($refreshToken);
        $this->faultIfNeeded('refresh:'.$refreshToken, $scenario);

        if ($scenario === 'refresh_fail') {
            throw new MarketplaceOAuthException('Refresh token sandbox gagal.', 'REFRESH_TOKEN_FAILED');
        }

        return $this->tokenSet($scenario, true, $externalStoreId);
    }

    private function tokenSet(string $scenario, bool $refresh = false, ?string $externalStoreId = null): array
    {
        $suffix = Str::lower(Str::random(12));
        $shopId = $externalStoreId ?: 'SANDBOX-'.strtoupper(Str::slug($this->platform, '-')).'-001';

        $shops = $scenario === 'multi_store'
            ? [
                ['id' => $shopId, 'name' => $this->platform.' Sandbox 1', 'cipher' => $this->cipher()],
                ['id' => $shopId.'-2', 'name' => $this->platform.' Sandbox 2', 'cipher' => $this->cipher('2')],
            ]
            : [[
                'id' => $scenario === 'foreign_store' ? 'FOREIGN-'.$shopId : $shopId,
                'name' => $this->platform.' Sandbox Store',
                'cipher' => $this->cipher(),
            ]];

        return [
            'access_token' => 'sandbox-access|'.$this->platform.'|'.$scenario.'|'.$suffix,
            'refresh_token' => 'sandbox-refresh|'.$this->platform.'|'.$scenario.'|'.$suffix,
            'token_expires_at' => now()->addHour()->format('Y-m-d H:i:s'),
            'refresh_token_expires_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'granted_scopes' => $this->platform === 'TikTok Shop'
                ? ['seller.authorization.info', 'sandbox.read']
                : ['sandbox.read'],
            'shops' => $shops,
            'sandbox_refresh' => $refresh,
        ];
    }

    private function faultIfNeeded(string $operation, string $scenario): void
    {
        $this->attempts[$operation] = ($this->attempts[$operation] ?? 0) + 1;
        $attempt = $this->attempts[$operation];

        if ($scenario === 'timeout' || ($scenario === 'timeout_once' && $attempt === 1)) {
            throw new MarketplaceOAuthException('Provider sandbox timeout.', 'SANDBOX_TIMEOUT');
        }

        if ($scenario === 'rate_limit' || ($scenario === 'rate_limit_once' && $attempt === 1)) {
            throw new MarketplaceOAuthException('Provider sandbox rate limit.', 'SANDBOX_RATE_LIMITED');
        }
    }

    private function scenarioFromCode(string $code): string
    {
        $parts = explode('|', $code);
        $platform = (string) ($parts[1] ?? '');

        if (($parts[0] ?? '') !== 'sandbox' || strcasecmp($platform, Str::slug($this->platform, '-')) !== 0 || trim((string) ($parts[3] ?? '')) === '') {
            throw new MarketplaceOAuthException('Kode sandbox tidak sesuai platform.', 'INVALID_OAUTH_PLATFORM');
        }

        return $this->normalizeScenario((string) ($parts[2] ?? 'success'));
    }

    private function scenarioFromToken(string $token): string
    {
        $parts = explode('|', $token);
        if (($parts[0] ?? '') !== 'sandbox-refresh' || strcasecmp((string) ($parts[1] ?? ''), $this->platform) !== 0) {
            throw new MarketplaceOAuthException('Refresh token sandbox tidak sesuai platform.', 'INVALID_OAUTH_PLATFORM');
        }

        return $this->normalizeScenario((string) ($parts[2] ?? 'success'));
    }

    private function normalizeScenario(string $scenario): string
    {
        $allowed = [
            'success', 'multi_store', 'foreign_store', 'exchange_fail', 'refresh_fail',
            'timeout', 'timeout_once', 'rate_limit', 'rate_limit_once',
        ];

        return in_array($scenario, $allowed, true) ? $scenario : 'success';
    }

    private function cipher(string $suffix = ''): string
    {
        return 'sandbox-cipher-'.$this->platform.'-'.$suffix;
    }
}
