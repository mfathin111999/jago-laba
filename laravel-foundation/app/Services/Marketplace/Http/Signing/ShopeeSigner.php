<?php

namespace App\Services\Marketplace\Http\Signing;

/** Shopee Open Platform v2 request signature: HMAC-SHA256 over partner_id + path + timestamp [+ access_token] [+ shop_id]. */
final class ShopeeSigner
{
    public static function sign(string $partnerId, string $partnerKey, string $path, int $timestamp, ?string $accessToken = null, ?string $shopId = null): string
    {
        $base = $partnerId.$path.$timestamp;
        if ($accessToken !== null) {
            $base .= $accessToken;
        }
        if ($shopId !== null) {
            $base .= $shopId;
        }

        return hash_hmac('sha256', $base, $partnerKey);
    }
}
