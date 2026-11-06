<?php

namespace App\Services\Marketplace\OAuth;

use App\Exceptions\MarketplaceIntegrationException;
use App\Exceptions\MarketplaceOAuthException;
use App\Services\Marketplace\Http\MarketplaceHttp;
use App\Services\Marketplace\Sync\IntegrationPolicy;
use Throwable;

/**
 * Shared behaviour of the live Shopee / TikTok Shop providers: configuration check, error mapping onto the codes the
 * screens already explain, and a uniform token set. Settings come from config('services.*'), never from the browser.
 */
abstract class PartnerOAuthProvider implements LiveOAuthProvider
{
    /** @param array<string, mixed> $config */
    public function __construct(protected readonly array $config, protected readonly MarketplaceHttp $http) {}

    abstract public function platform(): string;

    /** @return list<string> */
    abstract public function configurationMissing(): array;

    abstract protected function buildAuthorizationUrl(string $state, string $redirectUri): string;

    /** @return array<string, mixed> */
    abstract protected function requestTokens(string $code, array $callback): array;

    /** @return array<string, mixed> */
    abstract protected function requestRefresh(string $refreshToken, ?string $externalStoreId, ?string $shopCipher): array;

    public function context(): array
    {
        return [
            'fingerprint' => IntegrationPolicy::fingerprint($this->platform(), $this->config),
            'environment' => IntegrationPolicy::environment($this->platform(), $this->config),
        ];
    }

    public function authorizationUrl(string $state, string $redirectUri, ?string $scenario = null): string
    {
        $missing = $this->configurationMissing();
        if ($missing !== []) {
            throw new MarketplaceOAuthException('Konfigurasi platform belum lengkap: '.implode(', ', $missing).'.', 'OAUTH_CONFIG_MISSING');
        }

        return $this->guard(fn (): string => $this->buildAuthorizationUrl($state, $redirectUri));
    }

    public function exchangeCode(string $code, array $callback): array
    {
        return $this->normalize($this->guard(fn (): array => $this->requestTokens($code, $callback)));
    }

    public function refreshToken(string $refreshToken, ?string $externalStoreId = null, ?string $shopCipher = null): array
    {
        return $this->normalize($this->guard(fn (): array => $this->requestRefresh($refreshToken, $externalStoreId, $shopCipher)));
    }

    protected function assertConfigured(): void
    {
        if ($this->configurationMissing() !== []) {
            throw new MarketplaceIntegrationException('Konfigurasi aplikasi belum lengkap.', 'OAUTH_NOT_CONFIGURED');
        }
    }

    protected function expiryFromSeconds(mixed $seconds): ?string
    {
        $seconds = (int) $seconds;

        return $seconds > 0 ? now()->addSeconds($seconds)->format('Y-m-d H:i:s') : null;
    }

    /**
     * Token sets may carry nulls; the service stores strings.
     *
     * @param  array<string, mixed>  $tokens
     * @return array<string, mixed>
     */
    private function normalize(array $tokens): array
    {
        return [
            'access_token' => (string) ($tokens['access_token'] ?? ''),
            'refresh_token' => (string) ($tokens['refresh_token'] ?? ''),
            'token_expires_at' => $tokens['token_expires_at'] ?? null,
            'refresh_token_expires_at' => $tokens['refresh_token_expires_at'] ?? null,
            'granted_scopes' => array_values(array_map('strval', (array) ($tokens['granted_scopes'] ?? []))),
            'shops' => array_values((array) ($tokens['shops'] ?? [])),
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function guard(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (MarketplaceIntegrationException $exception) {
            throw new MarketplaceOAuthException($exception->getMessage(), self::codeFor($exception->errorCode), $exception);
        } catch (MarketplaceOAuthException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new MarketplaceOAuthException('Koneksi ke platform gagal.', 'CONNECTION_NOT_VERIFIED', $exception);
        }
    }

    /** Maps the integration error codes onto the codes the screens already explain. */
    private static function codeFor(string $code): string
    {
        return match (true) {
            $code === 'HTTP_TRANSPORT_ERROR' => 'PROVIDER_TIMEOUT',
            str_contains($code, 'RATE') => 'PROVIDER_RATE_LIMITED',
            str_contains($code, 'REFRESH') => 'REFRESH_TOKEN_FAILED',
            str_contains($code, 'TOKEN') || str_contains($code, 'MISSING_SHOP') || $code === 'INVALID_PLATFORM_RESPONSE' => 'TOKEN_EXCHANGE_FAILED',
            default => 'CONNECTION_NOT_VERIFIED',
        };
    }
}
