<?php

/**
 * Runs the LIVE sync path (legacy engine behind MarketplaceLiveSyncService) against the staging MySQL with a fake
 * provider that answers every read with an empty page. It proves the machinery works inside Laravel: enqueue, steps,
 * checkpoints, success bookkeeping. No real provider is contacted. Prints one JSON line.
 *
 * Needs the staging environment (. scripts/development/staging-env.sh) and a MySQL database set up by the staging scripts.
 */

use App\Auth\LegacyUser;
use App\Marketplace\TokenVault;
use App\Services\Marketplace\MarketplaceLiveSyncService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

putenv('JL_MARKETPLACE_TOKEN_ENCRYPTION_KEY='.base64_encode(str_repeat('p', 32)));
config(['jago.oauth.mode' => 'live']);
$company = getenv('JL_COMPANY_KEY') ?: 'LOCAL-COMPANY';
$store = 'STR-LIVE-PROBE';

DB::table('marketplace_sync_jobs')->where('store_id', $store)->delete();
DB::table('marketplace_oauth_connections')->where('store_id', $store)->delete();
DB::table('marketplace_stores')->where('id', $store)->delete();
DB::table('marketplace_stores')->insert(['id' => $store, 'company_key' => $company, 'platform' => 'TikTok Shop', 'store_name' => 'Live Probe', 'store_identifier' => 'probe-ext', 'status' => 'Terhubung', 'authorization_status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);
$connectionId = DB::table('marketplace_oauth_connections')->insertGetId([
    'company_key' => $company, 'store_id' => $store, 'platform' => 'TikTok Shop', 'external_store_id' => 'probe-ext', 'external_shop_cipher' => 'probe-cipher', 'external_store_name' => 'Live Probe',
    'access_token_encrypted' => (new TokenVault)->encrypt('probe-access-token'), 'refresh_token_encrypted' => (new TokenVault)->encrypt('probe-refresh-token'),
    'token_expires_at' => now()->addDay(), 'refresh_token_expires_at' => now()->addDays(30), 'granted_scopes' => '[]', 'authorized_at' => now(), 'verified_at' => now(), 'status' => 'verified', 'created_at' => now(), 'updated_at' => now(),
]);

$calls = [];
$fake = new class($calls)
{
    public function __construct(private array &$calls) {}

    public function call(string $method, string $path, array $query, string $token, ?array $body = null): array
    {
        $this->calls[] = $method.' '.$path.' token='.$token;

        return ['total_count' => 0, 'products' => [], 'orders' => [], 'transactions' => [], 'return_orders' => [], 'returns' => [], 'next_page_token' => ''];
    }
};

/** @var MarketplaceLiveSyncService $sync */
$sync = $app->make(MarketplaceLiveSyncService::class);
$sync->useClientFactory(static fn (array $config): object => $fake);

$started = $sync->enqueue($company, $store, 'probe-request');
$steps = 0;
do {
    $state = $sync->step($company, $store, $started['run_key']);
    $steps++;
    if ($state['pending'] && $state['retry_in'] > 1) {
        break;
    }
} while ($state['pending'] && $steps < 60);

$jobs = DB::table('marketplace_sync_jobs')->where('store_id', $store)->orderBy('id')->get(['job_type', 'status', 'last_error_code'])->all();
$connection = DB::table('marketplace_oauth_connections')->where('id', $connectionId)->first(['last_successful_sync_at']);
echo json_encode([
    'started' => $started, 'steps' => $steps, 'pending' => $state['pending'], 'jobs' => $jobs,
    'last_successful_sync_at' => $connection->last_successful_sync_at,
    'provider_calls' => count($calls), 'token_seen_by_provider' => $calls[0] ?? null,
    'user' => (new LegacyUser(['id' => 'x']))->getAuthIdentifier(),
], JSON_UNESCAPED_SLASHES)."\n";
