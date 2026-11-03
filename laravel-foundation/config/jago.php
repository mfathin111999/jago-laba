<?php

$appBaseUrl = rtrim((string) env('JL_APP_BASE_URL', ''), '/');
$shopeeRegion = strtolower((string) env('JL_SHOPEE_REGION', 'live'));
$shopeeAuthDefault = $shopeeRegion === 'sandbox'
    ? 'https://open.sandbox.test-stable.shopee.com/auth'
    : 'https://open.shopee.com/auth';
$shopeeApiDefault = $shopeeRegion === 'sandbox'
    ? 'https://openplatform.sandbox.test-stable.shopee.sg'
    : 'https://partner.shopeemobile.com';
$tiktokRegion = strtolower((string) env('JL_TIKTOK_SHOP_REGION', 'row'));
$tiktokAuthHost = $tiktokRegion === 'us'
    ? 'services.us.tiktokshop.com'
    : 'services.tiktokshop.com';
$konveksiDatabaseConfigPath = (string) env(
    'JL_KONVEKSI_DATABASE_CONFIG',
    dirname(__DIR__, 2).'/storage/private/konveksi/database.php',
);
$konveksiFileIsolated = false;
if (is_file($konveksiDatabaseConfigPath)) {
    $konveksiConfig = require $konveksiDatabaseConfigPath;
    $konveksiFileIsolated = is_array($konveksiConfig)
        && filter_var($konveksiConfig['isolated'] ?? false, FILTER_VALIDATE_BOOL);
}
$konveksiIsolated = filter_var(
    env('JL_KONVEKSI_DATABASE_ISOLATED', $konveksiFileIsolated ? 'true' : 'false'),
    FILTER_VALIDATE_BOOL,
);

return [
    'company_key' => env('JL_COMPANY_KEY', 'LOCAL-COMPANY'),
    'konveksi_database_isolated' => $konveksiIsolated,
    'konveksi_database_config_path' => $konveksiDatabaseConfigPath,
    'legacy_session' => [
        'cookie' => env('JL_LEGACY_SESSION_COOKIE', 'JAGO_LABA_LOCAL_SESSION'),
        'save_path' => env('JL_LEGACY_SESSION_PATH'),
    ],
    'hosting' => [
        'document_root_public' => filter_var(env('JL_DOCUMENT_ROOT_PUBLIC', 'true'), FILTER_VALIDATE_BOOL),
        'allowed_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('JL_ALLOWED_HOSTS', '')),
        ))),
        'trusted_proxies' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('JL_TRUSTED_PROXIES', '')),
        ))),
    ],
    'integration' => [
        'base_url' => $appBaseUrl !== '' ? $appBaseUrl : null,
        'webhook_url' => env('JL_MARKETPLACE_WEBHOOK_URL'),
        'catalog_reconcile_seconds' => max(900, (int) env('JL_MARKETPLACE_CATALOG_RECONCILE_SECONDS', 86400)),
        'returns_reconcile_seconds' => max(900, (int) env('JL_MARKETPLACE_RETURNS_RECONCILE_SECONDS', 86400)),
        'shopee' => [
            'partner_id' => env('JL_SHOPEE_PARTNER_ID'),
            'partner_key' => env('JL_SHOPEE_PARTNER_KEY'),
            'region' => $shopeeRegion,
            'auth_url' => env('JL_SHOPEE_AUTH_URL', $shopeeAuthDefault),
            'api_base_url' => rtrim((string) env('JL_SHOPEE_API_BASE_URL', $shopeeApiDefault), '/'),
        ],
        'tiktok_shop' => [
            'app_key' => env('JL_TIKTOK_SHOP_APP_KEY'),
            'app_secret' => env('JL_TIKTOK_SHOP_APP_SECRET'),
            'service_id' => env('JL_TIKTOK_SHOP_SERVICE_ID'),
            'market' => env('JL_TIKTOK_SHOP_MARKET'),
            'region' => $tiktokRegion,
            'auth_url' => env('JL_TIKTOK_SHOP_AUTH_URL', 'https://'.$tiktokAuthHost.'/open/authorize'),
            'api_base_url' => rtrim((string) env('JL_TIKTOK_SHOP_API_BASE_URL', 'https://open-api.tiktokglobalshop.com'), '/'),
            'required_scopes' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('JL_TIKTOK_SHOP_REQUIRED_SCOPES', 'seller.authorization.info')),
            ))),
        ],
    ],
    'oauth' => [
        // The modernization boundary is sandbox-only until provider URLs are
        // registered and a separate Live cutover is approved.
        'mode' => env('JL_MARKETPLACE_OAUTH_MODE', 'sandbox'),
        'platforms' => ['Shopee', 'TikTok Shop', 'Tokopedia', 'Lazada', 'Blibli'],
        'state_ttl_seconds' => max(60, (int) env('JL_MARKETPLACE_OAUTH_STATE_TTL', 600)),
        'return_path' => 'marketplace.php?scope=global&tab=integrasi',
        'retry' => [
            'max_attempts' => max(1, (int) env('JL_MARKETPLACE_OAUTH_RETRY_ATTEMPTS', 2)),
        ],
    ],
    'sync' => [
        'queue' => env('JL_MARKETPLACE_SYNC_QUEUE', 'marketplace'),
        'page_size' => max(1, (int) env('JL_MARKETPLACE_SYNC_PAGE_SIZE', 50)),
        'page_size_max' => max(1, (int) env('JL_MARKETPLACE_SYNC_PAGE_SIZE_MAX', 100)),
        'max_pages' => max(1, (int) env('JL_MARKETPLACE_SYNC_MAX_PAGES', 20)),
        'max_pages_max' => max(1, (int) env('JL_MARKETPLACE_SYNC_MAX_PAGES_MAX', 100)),
        'max_stores' => max(1, (int) env('JL_MARKETPLACE_SYNC_MAX_STORES', 20)),
        'max_stores_max' => max(1, (int) env('JL_MARKETPLACE_SYNC_MAX_STORES_MAX', 100)),
        'lease_seconds' => max(45, (int) env('JL_MARKETPLACE_SYNC_LEASE_SECONDS', 45)),
    ],
    'secrets' => [
        // Do not place the key itself in config: the foundation does not use it yet.
        'marketplace_token_encryption_key_configured' => trim((string) env('JL_MARKETPLACE_TOKEN_ENCRYPTION_KEY', '')) !== '',
        'marketplace_token_encryption_key_file' => env('JL_MARKETPLACE_TOKEN_ENCRYPTION_KEY_FILE'),
    ],
    'store_deletion' => [
        // Durable deletion-intent ledger; keep it outside the web root and outside the database.
        'ledger_dir' => env('JL_DELETION_LEDGER_DIR') ?: null,
    ],
    'backup' => [
        // Backups hold business data: keep them outside the web root.
        'local_directory' => env('JL_BACKUP_DIR') ?: null,
        'external_directory' => env('JL_BACKUP_MIRROR_DIR') ?: null,
        // Absolute path to mysqldump; without it only the JSON snapshot is produced.
        'mysqldump' => env('JL_MYSQLDUMP') ?: null,
    ],
];
