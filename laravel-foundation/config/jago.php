<?php

$appBaseUrl = rtrim((string) env('JL_APP_BASE_URL', ''), '/');
$konveksiDatabaseConfigPath = (string) env(
    'JL_KONVEKSI_DATABASE_CONFIG',
    storage_path('private/konveksi/database.php'),
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

// The legacy code reads its settings with getenv(). Laravel only exposes .env values through env() while the
// configuration is being built, so collect every JL_* setting here: after `php artisan config:cache` the bridge
// (LegacyRuntime, TokenVault) reads this array instead of calling env() at runtime.
$legacyEnvironment = [];
foreach (array_merge($_ENV, $_SERVER) as $name => $value) {
    if (is_string($name) && str_starts_with($name, 'JL_') && is_string($value) && $value !== '') {
        $legacyEnvironment[$name] = $value;
    }
}
foreach (['DB_HOST' => 'JL_DB_HOST', 'DB_PORT' => 'JL_DB_PORT', 'DB_DATABASE' => 'JL_DB_NAME', 'DB_USERNAME' => 'JL_DB_USER', 'DB_PASSWORD' => 'JL_DB_PASS'] as $laravelName => $legacyName) {
    $value = env($laravelName);
    if (! isset($legacyEnvironment[$legacyName]) && is_scalar($value) && (string) $value !== '') {
        $legacyEnvironment[$legacyName] = (string) $value;
    }
}

return [
    'legacy_env' => $legacyEnvironment,
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
        'returns_reconcile_seconds' => max(900, (int) env('JL_MARKETPLACE_RETURNS_RECONCILE_SECONDS', 900)),
        // Provider credentials and hosts are in config/services.php ('shopee', 'tiktok_shop').
    ],
    'oauth' => [
        // The modernization boundary is sandbox-only until provider URLs are
        // registered and a separate Live cutover is approved.
        'mode' => env('JL_MARKETPLACE_OAUTH_MODE', 'sandbox'),
        'platforms' => ['Shopee', 'TikTok Shop', 'Tokopedia', 'Lazada', 'Blibli'],
        'state_ttl_seconds' => max(60, (int) env('JL_MARKETPLACE_OAUTH_STATE_TTL', 600)),
        'return_path' => 'marketplace/integrations',
        'retry' => [
            'max_attempts' => max(1, (int) env('JL_MARKETPLACE_OAUTH_RETRY_ATTEMPTS', 2)),
        ],
    ],
    'sync' => [
        // Conservative Jago Laba concurrency policy per platform, not an official API quota.
        'platform_concurrency' => ['Shopee' => 2, 'TikTok Shop' => 2],
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
    'documents' => [
        // Private storage root for uploaded documents; point it at the legacy storage root to keep existing files reachable.
        'root' => env('JL_DOCUMENT_STORAGE_ROOT') ?: null,
    ],
    'konveksi' => [
        // The legacy Konveksi single-page client (HTML views) is served by Laravel; see KonveksiLegacyAppController.
        'views_path' => env('JL_KONVEKSI_VIEWS_PATH') ?: resource_path('konveksi/views'),
        // Cache-busting token for assets/konveksi/js/jl-api.js.
        'client_version' => '20261029',
    ],
];
