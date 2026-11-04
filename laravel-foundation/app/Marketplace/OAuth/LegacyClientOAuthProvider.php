<?php

namespace App\Marketplace\OAuth;

use App\Exceptions\MarketplaceOAuthException;
use MarketplaceIntegrationException;
use MarketplaceOAuthClient;
use ShopeeOAuthClient;
use Throwable;
use TikTokShopOAuthClient;

/**
 * The LIVE OAuth provider: it runs the legacy Shopee / TikTok Shop OAuth clients unchanged (same URLs, signatures,
 * token exchange and refresh), so the authorization flow behaves exactly as in the legacy application.
 * Settings come from the environment (config jago.integration.*); nothing is read from the browser.
 */
final class LegacyClientOAuthProvider implements MarketplaceOAuthProvider
{
    public const PLATFORMS = ['Shopee', 'TikTok Shop'];

    private MarketplaceOAuthClient $client;

    /**
     * @param  array<string, mixed>|null  $config  provider settings; defaults to config('jago.integration.<platform>')
     * @param  callable|null  $transport  test seam for the Shopee client (method, url, headers, body) => array
     */
    public function __construct(private readonly string $platform, ?array $config = null, ?MarketplaceOAuthClient $client = null, ?callable $transport = null)
    {
        if (! in_array($platform, self::PLATFORMS, true)) {
            throw new MarketplaceOAuthException('Platform Marketplace belum tersedia untuk koneksi live.', 'PLATFORM_UNSUPPORTED');
        }
        self::loadLegacyClasses();
        $config ??= (array) config('jago.integration.'.($platform === 'Shopee' ? 'shopee' : 'tiktok_shop'), []);
        $this->client = $client ?? ($platform === 'Shopee'
            ? new ShopeeOAuthClient(null, $config, $transport)
            : new TikTokShopOAuthClient(null, $config));
    }

    public function platform(): string
    {
        return $this->platform;
    }

    /** @return list<string> environment variable names that are still empty */
    public function configurationMissing(): array
    {
        return $this->client->configurationMissing();
    }

    /** @return array{fingerprint?: string, environment?: string} */
    public function context(): array
    {
        return method_exists($this->client, 'authorizationContext') ? $this->client->authorizationContext() : [];
    }

    public function authorizationUrl(string $state, string $redirectUri, ?string $scenario = null): string
    {
        $missing = $this->configurationMissing();
        if ($missing !== []) {
            throw new MarketplaceOAuthException('Konfigurasi platform belum lengkap: '.implode(', ', $missing).'.', 'OAUTH_CONFIG_MISSING');
        }

        return $this->guard(fn (): string => $this->client->authorizationUrl($state, $redirectUri));
    }

    public function exchangeCode(string $code, array $callback): array
    {
        return $this->normalize($this->guard(fn (): array => $this->client->exchangeCode($code, $callback)));
    }

    public function refreshToken(string $refreshToken, ?string $externalStoreId = null, ?string $shopCipher = null): array
    {
        return $this->normalize($this->guard(fn (): array => $this->client->refreshToken($refreshToken, $externalStoreId, $shopCipher)));
    }

    /**
     * Legacy token sets may carry nulls; the Laravel service stores strings.
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

    /** Maps the legacy error codes onto the codes the screens already explain. */
    private static function codeFor(string $legacy): string
    {
        return match (true) {
            $legacy === 'HTTP_TRANSPORT_ERROR' => 'PROVIDER_TIMEOUT',
            str_contains($legacy, 'RATE') => 'PROVIDER_RATE_LIMITED',
            str_contains($legacy, 'REFRESH') => 'REFRESH_TOKEN_FAILED',
            str_contains($legacy, 'TOKEN') || str_contains($legacy, 'MISSING_SHOP') || $legacy === 'INVALID_PLATFORM_RESPONSE' => 'TOKEN_EXCHANGE_FAILED',
            default => 'CONNECTION_NOT_VERIFIED',
        };
    }

    private static function loadLegacyClasses(): void
    {
        if (class_exists(\MarketplaceHttpClient::class, false)) {
            return;
        }
        $root = dirname(__DIR__, 4).'/app/Modules/Marketplace';
        foreach ([
            '/Integrations/Support/MarketplaceIntegrationException.php', '/Integrations/Support/MarketplaceHttpClient.php', '/Integrations/Support/MarketplaceApiOrigin.php',
            '/Integrations/Contracts/MarketplaceOAuthClient.php', '/Services/MarketplaceIntegrationPolicy.php',
            '/Integrations/Shopee/ShopeeOAuthClient.php', '/Integrations/TikTokShop/TikTokShopOAuthClient.php',
        ] as $file) {
            require_once $root.$file;
        }
    }
}
