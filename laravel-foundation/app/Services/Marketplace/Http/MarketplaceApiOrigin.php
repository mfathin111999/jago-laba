<?php

namespace App\Services\Marketplace\Http;

use App\Exceptions\MarketplaceIntegrationException;

/** Never send signed requests or shop tokens to a configurable arbitrary host. */
final class MarketplaceApiOrigin
{
    public const SHOPEE_HOSTS = ['partner.shopeemobile.com', 'openplatform.sandbox.test-stable.shopee.sg'];

    public const TIKTOK_SHOP_HOSTS = ['open-api.tiktokglobalshop.com'];

    /** Every host a call may reach: the API hosts above plus TikTok's token host and TikTok Business (ads). */
    public const ALLOWED_HOSTS = [
        ...self::SHOPEE_HOSTS,
        ...self::TIKTOK_SHOP_HOSTS,
        'auth.tiktok-shops.com',
        'business-api.tiktok.com',
    ];

    /** Validates a configured API base for a platform and returns it without a trailing slash. */
    public static function validate(string $platform, string $base): string
    {
        $base = rtrim($base, '/');
        $url = parse_url($base);
        $hosts = $platform === 'Shopee' ? self::SHOPEE_HOSTS : self::TIKTOK_SHOP_HOSTS;
        if (! $url || ($url['scheme'] ?? '') !== 'https' || ! in_array($url['host'] ?? '', $hosts, true)
            || isset($url['user']) || isset($url['pass'])
            || isset($url['port']) || isset($url['query']) || isset($url['fragment']) || ! empty($url['path'])) {
            throw new MarketplaceIntegrationException('Host API marketplace tidak valid.', 'API_HOST_INVALID');
        }

        return $base;
    }

    /** Checked before every outgoing request: https, a known marketplace host, no credentials in the URL. */
    public static function assertUrl(string $url): void
    {
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || ! in_array($parts['host'] ?? '', self::ALLOWED_HOSTS, true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new MarketplaceIntegrationException('Host API marketplace tidak valid.', 'API_HOST_INVALID');
        }
    }

    /** Throws a Shopee rejection with a safe machine identifier; provider bodies and signed URLs are never included. */
    public static function shopeeError(string $error): never
    {
        $error = strtoupper($error);
        $error = preg_match('/^[A-Z0-9_.]{1,80}$/D', $error) ? $error : 'REJECTED';
        throw new MarketplaceIntegrationException('Permintaan Shopee ditolak. Periksa kode API dan izin sumber terkait.', 'SHOPEE_'.$error);
    }
}
