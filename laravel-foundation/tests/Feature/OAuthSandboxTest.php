<?php

namespace Tests\Feature;

use App\Pilots\Marketplace\MarketplaceStoreDeletionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class OAuthSandboxTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('o', 32))]);

        Schema::create('auth_users_v2', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('username')->unique();
            $table->string('name')->nullable();
            $table->text('password_hash');
            $table->string('role_id')->nullable();
            $table->string('status')->default('aktif');
            $table->timestamps();
        });
        Schema::create('workspace_user_access', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('user_key');
            $table->string('workspace');
        });
        Schema::create('marketplace_stores', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_key');
            $table->string('platform');
            $table->string('store_name')->nullable();
            $table->string('store_identifier')->nullable();
            $table->string('status')->default('Terhubung');
            $table->string('authorization_status')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
        Schema::create('marketplace_oauth_states', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('state_hash', 64)->unique();
            $table->string('company_key');
            $table->string('user_key');
            $table->string('platform');
            $table->string('return_path');
            $table->dateTime('expires_at');
            $table->dateTime('consumed_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('marketplace_oauth_pending', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('pending_hash', 64)->unique();
            $table->string('company_key');
            $table->string('user_key');
            $table->string('platform');
            $table->text('access_token_encrypted');
            $table->text('refresh_token_encrypted')->nullable();
            $table->dateTime('token_expires_at')->nullable();
            $table->dateTime('refresh_token_expires_at')->nullable();
            $table->text('granted_scopes')->nullable();
            $table->text('shops_json');
            $table->string('return_path');
            $table->dateTime('expires_at');
            $table->dateTime('consumed_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('marketplace_oauth_connections', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('company_key');
            $table->string('store_id');
            $table->string('platform');
            $table->string('external_store_id');
            $table->string('external_shop_cipher')->nullable();
            $table->string('external_store_name');
            $table->text('access_token_encrypted');
            $table->text('refresh_token_encrypted')->nullable();
            $table->dateTime('token_expires_at')->nullable();
            $table->dateTime('refresh_token_expires_at')->nullable();
            $table->text('granted_scopes')->nullable();
            $table->dateTime('authorized_at');
            $table->dateTime('verified_at');
            $table->dateTime('last_successful_sync_at')->nullable();
            $table->dateTime('last_sync_attempt_at')->nullable();
            $table->string('status')->default('verified');
            $table->string('last_error_code')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        DB::table('auth_users_v2')->insert([
            'username' => 'owner',
            'name' => 'OAuth Owner',
            'password_hash' => password_hash('owner-secret', PASSWORD_BCRYPT),
            'role_id' => 'ROLE-OWNER_SUPER_ADMIN',
            'status' => 'aktif',
        ]);
        DB::table('marketplace_stores')->insert([
            'id' => 'foreign-shopee',
            'company_key' => 'OTHER-COMPANY',
            'platform' => 'Shopee',
            'store_name' => 'Foreign Sandbox Store',
            'store_identifier' => 'FOREIGN-SANDBOX-SHOPEE-001',
            'authorization_status' => 'verified',
        ]);

        config(['jago.oauth.state_ttl_seconds' => 600, 'jago.oauth.retry.max_attempts' => 2]);
    }

    public function test_all_supported_platforms_report_sandbox_only_and_start_from_legacy_url(): void
    {
        $this->loginAndSelectMarketplace();

        $status = $this->getJson('/api/marketplace-oauth.php?action=status');
        $status->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.mode', 'sandbox')
            ->assertJsonPath('data.provider_live_enabled', false)
            ->assertJsonMissingPath('data.platforms.Shopee.access_token');

        $platforms = $status->json('data.platforms');
        $this->assertSame(['Shopee', 'TikTok Shop', 'Tokopedia', 'Lazada', 'Blibli'], array_keys($platforms));

        foreach (array_keys($platforms) as $platform) {
            $started = $this->startSandbox($platform);
            $this->assertStringContainsString('/__sandbox/marketplace/oauth/authorize', (string) $started['authorization_url']);
            $this->assertStringContainsString('/api/marketplace-oauth.php', (string) $started['callback_url']);
            $this->assertArrayNotHasKey('access_token', $started);
        }

        $this->withHeader('X-CSRF-TOKEN', csrf_token())
            ->postJson('/api/marketplace-oauth.php?action=disconnect', ['store_id' => 'sandbox-store'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'disconnected');
    }

    public function test_a_store_that_is_being_deleted_cannot_be_reconnected_but_a_later_authorization_can(): void
    {
        $this->loginAndSelectMarketplace();
        app(MarketplaceStoreDeletionService::class)->install();

        $first = $this->sandboxCallback($this->startSandbox('Shopee', 'success'));
        $first->assertOk();
        $storeId = (string) $first->json('data.store_id');
        $identity = (string) DB::table('marketplace_oauth_connections')->where('store_id', $storeId)->value('external_store_id');
        $this->assertNotSame('', $identity);

        $deletion = ['store_id' => $storeId, 'company_key' => (string) DB::table('marketplace_stores')->where('id', $storeId)->value('company_key'), 'platform' => 'Shopee',
            'identity_hash' => hash('sha256', 'Shopee:'.$identity), 'actor' => 'owner', 'requested_at' => now()->format('Y-m-d H:i:s'), 'status' => 'pending',
            'counts_json' => '{}', 'files_json' => '[]', 'updated_at' => now()];
        $started = $this->startSandbox('Shopee', 'success');
        DB::table('marketplace_store_deletions')->insert($deletion);
        DB::table('marketplace_oauth_connections')->where('store_id', $storeId)->update(['status' => 'disconnected']);

        $this->sandboxCallback($started)->assertStatus(409)->assertJsonPath('code', 'STORE_BEING_DELETED');
        $this->assertSame('disconnected', DB::table('marketplace_oauth_connections')->where('store_id', $storeId)->value('status'), 'a blocked callback must not revive the connection');

        DB::table('marketplace_store_deletions')->update(['status' => 'completed', 'requested_at' => now()->subHour()->format('Y-m-d H:i:s')]);
        $this->sandboxCallback($this->startSandbox('Shopee', 'success'))->assertOk()->assertJsonPath('data.status', 'success');
    }

    public function test_sandbox_callback_persists_one_store_encrypts_tokens_and_replay_is_rejected(): void
    {
        $this->loginAndSelectMarketplace();
        $started = $this->startSandbox('Shopee', 'success');
        $callback = $this->sandboxCallback($started);

        $callback->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'success')
            ->assertJsonPath('data.platform', 'Shopee')
            ->assertJsonMissingPath('data.access_token')
            ->assertJsonMissingPath('data.refresh_token');

        $storeId = (string) $callback->json('data.store_id');
        $connection = DB::table('marketplace_oauth_connections')->where('store_id', $storeId)->first();
        $this->assertNotNull($connection);
        $this->assertStringNotContainsString('sandbox-access', (string) $connection->access_token_encrypted);
        $this->assertStringNotContainsString('sandbox-refresh', (string) $connection->refresh_token_encrypted);

        $replay = $this->getJson($this->callbackPath($started));
        $replay->assertStatus(400)->assertJsonPath('code', 'INVALID_OAUTH_STATE');

        $expired = $this->startSandbox('Blibli');
        $expiredPath = $this->callbackPath($expired);
        parse_str((string) parse_url($expiredPath, PHP_URL_QUERY), $expiredQuery);
        DB::table('marketplace_oauth_states')
            ->where('state_hash', hash('sha256', (string) ($expiredQuery['state'] ?? '')))
            ->update(['expires_at' => now()->subMinute()]);
        $this->getJson($expiredPath)->assertStatus(400)->assertJsonPath('code', 'INVALID_OAUTH_STATE');
    }

    public function test_origin_session_exchange_timeout_rate_limit_and_refresh_boundaries_are_safe(): void
    {
        $this->loginAndSelectMarketplace();

        $originMismatch = $this->withHeaders([
            'Origin' => 'https://attacker.example',
            'X-CSRF-TOKEN' => csrf_token(),
        ])->postJson('/api/marketplace-oauth.php?action=start', ['platform' => 'Shopee']);
        $originMismatch->assertStatus(400)->assertJsonPath('code', 'OAUTH_ORIGIN_MISMATCH');
        $callbackUrl = route('legacy.marketplace.oauth', ['action' => 'callback']);
        $callbackOrigin = parse_url($callbackUrl, PHP_URL_SCHEME).'://'.parse_url($callbackUrl, PHP_URL_HOST)
            .(parse_url($callbackUrl, PHP_URL_PORT) !== null ? ':'.parse_url($callbackUrl, PHP_URL_PORT) : '');
        $this->withHeader('Origin', $callbackOrigin);

        $exchangeFailure = $this->sandboxCallback($this->startSandbox('Shopee', 'exchange_fail'));
        $exchangeFailure->assertStatus(502)->assertJsonPath('code', 'TOKEN_EXCHANGE_FAILED');

        $timeoutRetry = $this->sandboxCallback($this->startSandbox('TikTok Shop', 'timeout_once'));
        $timeoutRetry->assertOk()->assertJsonPath('data.status', 'success');
        $rateLimitRetry = $this->sandboxCallback($this->startSandbox('Tokopedia', 'rate_limit_once'));
        $rateLimitRetry->assertOk()->assertJsonPath('data.status', 'success');

        $storeId = (string) $timeoutRetry->json('data.store_id');
        $refresh = $this->withHeader('X-CSRF-TOKEN', csrf_token())
            ->postJson('/api/marketplace-oauth.php?action=refresh', ['store_id' => $storeId]);
        $refresh->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.refreshed', true)
            ->assertJsonMissingPath('data.access_token');

        $refreshFailure = $this->sandboxCallback($this->startSandbox('Blibli', 'refresh_fail'));
        $refreshFailure->assertOk()->assertJsonPath('data.status', 'success');
        $failedStoreId = (string) $refreshFailure->json('data.store_id');
        $failedRefresh = $this->withHeader('X-CSRF-TOKEN', csrf_token())
            ->postJson('/api/marketplace-oauth.php?action=refresh', ['store_id' => $failedStoreId]);
        $failedRefresh->assertStatus(502)->assertJsonPath('code', 'REFRESH_TOKEN_FAILED');
        $this->assertSame('reauthorize', DB::table('marketplace_oauth_connections')->where('store_id', $failedStoreId)->value('status'));
    }

    public function test_multi_store_selection_and_foreign_company_are_isolated(): void
    {
        $this->loginAndSelectMarketplace();
        $started = $this->startSandbox('Lazada', 'multi_store');
        $callback = $this->sandboxCallback($started);

        $callback->assertOk()->assertJsonPath('data.status', 'choose')->assertJsonCount(2, 'data.shops');
        $pendingId = (string) $callback->json('data.pending_id');
        $shops = $callback->json('data.shops');
        $selected = (string) $shops[1]['id'];

        $pending = $this->getJson('/api/marketplace-oauth.php?action=pending&pending_id='.rawurlencode($pendingId));
        $pending->assertOk()->assertJsonCount(2, 'data.shops')->assertJsonMissingPath('data.access_token');

        $selectedResponse = $this->withHeader('X-CSRF-TOKEN', csrf_token())
            ->postJson('/api/marketplace-oauth.php?action=select', [
                'pending_id' => $pendingId,
                'external_store_id' => $selected,
            ]);
        $selectedResponse->assertOk()->assertJsonPath('data.status', 'success')->assertJsonMissingPath('data.refresh_token');

        $foreign = $this->sandboxCallback($this->startSandbox('Shopee', 'foreign_store'));
        $foreign->assertStatus(400)->assertJsonPath('code', 'STORE_OWNED_BY_OTHER_COMPANY');
        $this->assertSame(1, DB::table('marketplace_oauth_connections')->count());
    }

    public function test_callback_requires_the_authenticated_legacy_session(): void
    {
        $this->loginAndSelectMarketplace();
        $started = $this->startSandbox('Blibli');
        Auth::guard('web')->logout();
        session()->flush();

        $this->getJson($this->callbackPath($started))
            ->assertStatus(401)
            ->assertJsonPath('code', 'AUTH_REQUIRED');
    }

    /** @return array<string, mixed> */
    private function startSandbox(string $platform, string $scenario = 'success'): array
    {
        $response = $this->withHeaders(['X-CSRF-TOKEN' => csrf_token()])
            ->postJson('/api/marketplace-oauth.php?action=start', [
                'platform' => $platform,
                'scenario' => $scenario,
                'return_path' => 'marketplace.php?scope=global&tab=integrasi',
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        return (array) $response->json('data');
    }

    private function sandboxCallback(array $started): TestResponse
    {
        $authorize = $this->get($this->pathFromUrl((string) $started['authorization_url']));
        $authorize->assertStatus(303);

        return $this->getJson($this->pathFromUrl((string) $authorize->headers->get('Location')));
    }

    private function callbackPath(array $started): string
    {
        $authorize = $this->get($this->pathFromUrl((string) $started['authorization_url']));
        $authorize->assertStatus(303);

        return $this->pathFromUrl((string) $authorize->headers->get('Location'));
    }

    private function pathFromUrl(string $url): string
    {
        $parsed = parse_url($url);
        $path = (string) ($parsed['path'] ?? '/');
        $query = (string) ($parsed['query'] ?? '');

        return $path.($query !== '' ? '?'.$query : '');
    }

    private function loginAndSelectMarketplace(): void
    {
        $this->postJson('/api/rpc.php', [
            'method' => 'prosesLoginBackend',
            'args' => ['owner', 'owner-secret'],
        ])->assertOk()->assertJsonPath('result.status', 'success');
        $this->withHeader('X-CSRF-TOKEN', csrf_token())
            ->postJson('/api/workspace.php', ['workspace' => 'marketplace'])
            ->assertOk();
    }
}
