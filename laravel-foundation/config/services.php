<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Marketplace partner applications. `region` is 'live' or 'sandbox' for Shopee; hosts are checked again by
    // MarketplaceApiOrigin before any request is sent.
    'shopee' => (static function (): array {
        $region = strtolower((string) env('JL_SHOPEE_REGION', 'live'));
        $sandbox = $region === 'sandbox';

        return [
            'partner_id' => env('JL_SHOPEE_PARTNER_ID'),
            'partner_key' => env('JL_SHOPEE_PARTNER_KEY'),
            'region' => $region,
            'auth_url' => env('JL_SHOPEE_AUTH_URL', $sandbox ? 'https://open.sandbox.test-stable.shopee.com/auth' : 'https://open.shopee.com/auth'),
            'api_base_url' => rtrim((string) env('JL_SHOPEE_API_BASE_URL', $sandbox ? 'https://openplatform.sandbox.test-stable.shopee.sg' : 'https://partner.shopeemobile.com'), '/'),
        ];
    })(),

    'tiktok_shop' => (static function (): array {
        $region = strtolower((string) env('JL_TIKTOK_SHOP_REGION', 'row'));

        return [
            'app_key' => env('JL_TIKTOK_SHOP_APP_KEY'),
            'app_secret' => env('JL_TIKTOK_SHOP_APP_SECRET'),
            'service_id' => env('JL_TIKTOK_SHOP_SERVICE_ID'),
            'market' => env('JL_TIKTOK_SHOP_MARKET'),
            'region' => $region,
            'auth_url' => env('JL_TIKTOK_SHOP_AUTH_URL', 'https://'.($region === 'us' ? 'services.us.tiktokshop.com' : 'services.tiktokshop.com').'/open/authorize'),
            'api_base_url' => rtrim((string) env('JL_TIKTOK_SHOP_API_BASE_URL', 'https://open-api.tiktokglobalshop.com'), '/'),
            'required_scopes' => array_values(array_filter(array_map('trim', explode(',', (string) env('JL_TIKTOK_SHOP_REQUIRED_SCOPES', 'seller.authorization.info'))))),
        ];
    })(),

];
