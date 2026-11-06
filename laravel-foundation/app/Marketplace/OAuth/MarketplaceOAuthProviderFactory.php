<?php

namespace App\Marketplace\OAuth;

use App\Exceptions\MarketplaceOAuthException;
use App\Services\Marketplace\Http\MarketplaceHttp;

/** Builds the OAuth provider for a platform: the real one in live mode, the sandbox otherwise. Bound in AppServiceProvider. */
final class MarketplaceOAuthProviderFactory
{
    /** Platforms that can be connected to the real API. */
    public const LIVE_PLATFORMS = ['Shopee', 'TikTok Shop'];

    public function __construct(private readonly MarketplaceHttp $http) {}

    public function live(): bool
    {
        return strtolower((string) config('jago.oauth.mode', 'sandbox')) === 'live';
    }

    public function for(string $platform): MarketplaceOAuthProvider
    {
        if (! $this->live()) {
            return new SandboxMarketplaceOAuthProvider($platform);
        }

        return match ($platform) {
            'Shopee' => new ShopeeOAuthProvider((array) config('services.shopee', []), $this->http),
            'TikTok Shop' => new TikTokShopOAuthProvider((array) config('services.tiktok_shop', []), $this->http),
            default => throw new MarketplaceOAuthException('Platform Marketplace belum tersedia untuk koneksi live.', 'PLATFORM_UNSUPPORTED'),
        };
    }
}
