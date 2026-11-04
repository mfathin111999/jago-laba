<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use App\Marketplace\OAuth\LegacyClientOAuthProvider;
use App\Pilots\Marketplace\MarketplaceOAuthService;
use App\Pilots\Marketplace\MarketplaceStoreDeletionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Live OAuth: the legacy Shopee / TikTok clients run behind the Laravel flow; the provider's HTTP is faked here. */
final class LiveOAuthTest extends TestCase
{
    /** @var list<array{string, string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('o', 32)), 'jago.oauth.state_ttl_seconds' => 600, 'jago.oauth.retry.max_attempts' => 1]);

        Schema::create('workspace_user_access', function (Blueprint $table): void {
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
        DB::table('workspace_user_access')->insert([['user_key' => 'u1', 'workspace' => 'marketplace']]);

        config([
            'jago.oauth.mode' => 'live',
            'jago.integration.shopee' => ['partner_id' => '100001', 'partner_key' => 'test-partner-key-not-real', 'region' => 'live', 'auth_url' => 'https://open.shopee.com/auth', 'api_base_url' => 'https://partner.shopeemobile.com'],
            'jago.integration.tiktok_shop' => ['app_key' => '', 'app_secret' => '', 'service_id' => '', 'auth_url' => 'https://services.tiktokshop.com/open/authorize', 'api_base_url' => 'https://open-api.tiktokglobalshop.com'],
        ]);
        $this->actingAs(new LegacyUser(['id' => 'u1', 'username' => 'u1', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN', 'company_key' => 'A']), 'web');
        session(['_token' => 'tok', 'workspace' => 'marketplace']);
    }

    /** Installs a Shopee provider whose HTTP calls are answered by $answer(method, url, body). */
    private function fakeShopee(callable $answer): void
    {
        $service = new MarketplaceOAuthService(app('db'), app(MarketplaceStoreDeletionService::class));
        app()->instance(MarketplaceOAuthService::class, $service);
        $service->registerProvider(new LegacyClientOAuthProvider('Shopee', null, null, function (string $method, string $url, array $headers = [], ?array $body = null) use ($answer): array {
            $this->calls[] = [$method, $url];

            return $answer($method, $url, $body);
        }));
    }

    /** @param array<string, mixed> $payload */
    private function oauth(string $action, array $payload = []): TestResponse
    {
        return $this->withHeader('X-CSRF-TOKEN', 'tok')->postJson('/api/marketplace-oauth.php?action='.$action, $payload);
    }

    /** @return array<string, mixed> */
    private function start(): array
    {
        return (array) $this->oauth('start', ['platform' => 'Shopee', 'return_path' => 'marketplace/integrations'])->assertOk()->json('data');
    }

    /** @param array<string, mixed> $started */
    private function callbackQuery(array $started): string
    {
        parse_str((string) parse_url((string) $started['authorization_url'], PHP_URL_QUERY), $query);

        return '/api/marketplace-oauth.php?'.http_build_query(['action' => 'callback', 'state' => $query['state'], 'code' => 'abc123', 'shop_id' => '1234567']);
    }

    public function test_live_status_lists_only_supported_platforms_and_says_what_is_missing(): void
    {
        $status = $this->getJson('/api/marketplace-oauth.php?action=status')->assertOk();

        $status->assertJsonPath('data.mode', 'live')->assertJsonPath('data.provider_live_enabled', true)
            ->assertJsonPath('data.platforms.Shopee.ready', true)
            ->assertJsonPath('data.platforms.Shopee.environment', 'live')
            ->assertJsonPath('data.platforms.TikTok Shop.ready', false);
        $this->assertSame(['Shopee', 'TikTok Shop'], array_keys($status->json('data.platforms')));
        $this->assertContains('JL_TIKTOK_SHOP_APP_KEY', $status->json('data.platforms.TikTok Shop.missing'));
        $this->assertStringNotContainsString('test-partner-key-not-real', $status->getContent(), 'secrets never reach the browser');
    }

    public function test_starting_shopee_builds_the_official_authorization_url_and_never_offers_sandbox_scenarios(): void
    {
        $started = $this->oauth('start', ['platform' => 'Shopee', 'scenario' => 'exchange_fail'])->assertOk()->json('data');

        $this->assertSame('live', $started['environment']);
        $this->assertStringStartsWith('https://open.shopee.com/auth?', $started['authorization_url']);
        parse_str((string) parse_url($started['authorization_url'], PHP_URL_QUERY), $query);
        $this->assertSame('100001', $query['partner_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame($started['callback_url'], $query['redirect_uri']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $query['state']);
        $this->assertArrayNotHasKey('scenario', $query);
    }

    public function test_an_unconfigured_or_unsupported_platform_is_refused_with_a_clear_code(): void
    {
        $this->oauth('start', ['platform' => 'TikTok Shop'])->assertStatus(400)->assertJsonPath('code', 'OAUTH_CONFIG_MISSING');
        $this->oauth('start', ['platform' => 'Lazada'])->assertStatus(400);
    }

    public function test_the_callback_exchanges_the_code_verifies_the_shop_and_stores_encrypted_tokens(): void
    {
        $this->fakeShopee(fn (string $method, string $url): array => match (true) {
            str_contains($url, '/api/v2/auth/token/get') => ['response' => ['access_token' => 'LIVE-ACCESS-TOKEN', 'refresh_token' => 'LIVE-REFRESH-TOKEN', 'expire_in' => 14400, 'refresh_token_expire_in' => 2592000]],
            str_contains($url, '/api/v2/shop/get_shop_info') => ['response' => ['shop_id' => '1234567', 'shop_name' => 'Toko Nyata Shopee']],
            default => ['error' => 'unexpected'],
        });

        $callback = $this->getJson($this->callbackQuery($this->start()))->assertOk();
        $callback->assertJsonPath('data.status', 'success')->assertJsonPath('data.platform', 'Shopee');

        $connection = DB::table('marketplace_oauth_connections')->where('external_store_id', '1234567')->first();
        $this->assertNotNull($connection);
        $this->assertSame('verified', $connection->status);
        $this->assertSame('A', $connection->company_key);
        $this->assertSame('Toko Nyata Shopee', $connection->external_store_name);
        $this->assertStringNotContainsString('LIVE-ACCESS-TOKEN', (string) $connection->access_token_encrypted);
        $this->assertNotNull($connection->token_expires_at);
        $this->assertSame('Toko Nyata Shopee', DB::table('marketplace_stores')->where('id', $connection->store_id)->value('store_name'));
        $this->assertStringNotContainsString('LIVE-ACCESS-TOKEN', $callback->getContent());

        $signed = array_filter($this->calls, static fn (array $call): bool => str_contains($call[1], 'partner_id=100001') && str_contains($call[1], 'sign='));
        $this->assertCount(2, $signed, 'both provider calls are signed with the partner id');
    }

    public function test_a_provider_rejection_stores_nothing_and_is_reported_safely(): void
    {
        $this->fakeShopee(fn (): array => ['error' => 'error_auth', 'message' => 'invalid code SECRET-DETAIL']);

        $response = $this->getJson($this->callbackQuery($this->start()))->assertStatus(400);

        $this->assertStringNotContainsString('SECRET-DETAIL', $response->getContent());
        $this->assertSame(0, DB::table('marketplace_oauth_connections')->count());
        $this->assertSame(0, DB::table('marketplace_stores')->where('company_key', 'A')->count());
    }

    public function test_refresh_runs_through_the_provider_and_updates_the_stored_token(): void
    {
        $this->fakeShopee(fn (string $method, string $url): array => match (true) {
            str_contains($url, '/auth/token/get') => ['response' => ['access_token' => 'A1', 'refresh_token' => 'R1', 'expire_in' => 60, 'refresh_token_expire_in' => 2592000]],
            str_contains($url, '/auth/access_token/get') => ['response' => ['access_token' => 'A2', 'refresh_token' => 'R2', 'expire_in' => 14400, 'refresh_token_expire_in' => 2592000]],
            default => ['response' => ['shop_id' => '1234567', 'shop_name' => 'Toko Nyata Shopee']],
        });
        $storeId = (string) $this->getJson($this->callbackQuery($this->start()))->assertOk()->json('data.store_id');
        $before = DB::table('marketplace_oauth_connections')->where('store_id', $storeId)->value('access_token_encrypted');

        $this->oauth('refresh', ['store_id' => $storeId])->assertOk()->assertJsonPath('success', true);

        $this->assertNotSame($before, DB::table('marketplace_oauth_connections')->where('store_id', $storeId)->value('access_token_encrypted'));
        $this->assertTrue(collect($this->calls)->contains(static fn (array $call): bool => str_contains($call[1], '/auth/access_token/get')));
    }
}
