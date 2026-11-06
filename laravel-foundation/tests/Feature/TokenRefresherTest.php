<?php

namespace Tests\Feature;

use App\Exceptions\MarketplaceIntegrationException;
use App\Services\Marketplace\MarketplaceScope;
use App\Services\Marketplace\Sync\TokenRefresher;
use App\Services\Marketplace\TokenVault;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class TokenRefresherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('o', 32)),
            'jago.oauth.mode' => 'live',
            'services.shopee' => ['partner_id' => '100001', 'partner_key' => 'test-partner-key-not-real', 'region' => 'live', 'auth_url' => 'https://open.shopee.com/auth', 'api_base_url' => 'https://partner.shopeemobile.com'],
        ]);
        Schema::create('marketplace_stores', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_key');
            $table->string('platform');
            $table->string('store_name')->nullable();
            $table->string('authorization_status')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
        Schema::create('marketplace_oauth_connections', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('company_key');
            $table->string('store_id');
            $table->string('platform');
            $table->string('external_store_id');
            $table->string('external_shop_cipher')->nullable();
            $table->text('access_token_encrypted');
            $table->text('refresh_token_encrypted')->nullable();
            $table->dateTime('token_expires_at')->nullable();
            $table->dateTime('refresh_token_expires_at')->nullable();
            $table->text('granted_scopes')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->string('status')->default('verified');
            $table->string('last_error_code')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        Schema::create('marketplace_partner_settings', function (Blueprint $table): void {
            $table->string('platform')->primary();
            $table->text('settings_encrypted');
            $table->string('last_test_status')->nullable();
            $table->dateTime('last_tested_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        DB::table('marketplace_stores')->insert(['id' => 'S1', 'company_key' => 'A', 'platform' => 'Shopee', 'store_name' => 'Toko', 'authorization_status' => 'connected']);
        $vault = app(TokenVault::class);
        DB::table('marketplace_oauth_connections')->insert([
            'company_key' => 'A', 'store_id' => 'S1', 'platform' => 'Shopee', 'external_store_id' => '1234567',
            'access_token_encrypted' => $vault->encrypt('OLD-ACCESS'), 'refresh_token_encrypted' => $vault->encrypt('OLD-REFRESH'),
            'token_expires_at' => now()->subMinute()->format('Y-m-d H:i:s'),
        ]);
    }

    private function refresh(bool $force = true): array
    {
        return app(MarketplaceScope::class)->forCompany('A', fn (): array => app(TokenRefresher::class)->refresh('S1', $force));
    }

    public function test_an_expired_token_is_renewed_and_stored_encrypted(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/auth/access_token/get*' => Http::response(['response' => ['access_token' => 'NEW-ACCESS', 'refresh_token' => 'NEW-REFRESH', 'expire_in' => 14400, 'refresh_token_expire_in' => 2592000]]),
            'partner.shopeemobile.com/api/v2/shop/get_shop_info*' => Http::response(['response' => ['shop_id' => '1234567', 'shop_name' => 'Toko']]),
        ]);

        $this->assertSame('verified', $this->refresh(false)['status']);

        $row = DB::table('marketplace_oauth_connections')->first();
        $vault = app(TokenVault::class);
        $this->assertSame('NEW-ACCESS', $vault->decrypt($row->access_token_encrypted));
        $this->assertSame('NEW-REFRESH', $vault->decrypt($row->refresh_token_encrypted));
        $this->assertNull($row->last_error_code);
        $this->assertGreaterThan(now()->addHour()->format('Y-m-d H:i:s'), $row->token_expires_at);
    }

    public function test_a_token_that_is_still_valid_is_left_alone_unless_forced(): void
    {
        DB::table('marketplace_oauth_connections')->update(['token_expires_at' => now()->addHour()->format('Y-m-d H:i:s')]);
        Http::fake();

        $this->assertSame('Token sudah diperbarui proses lain.', $this->refresh(false)['message']);
        Http::assertNothingSent();
    }

    public function test_a_rejected_refresh_marks_the_store_for_reauthorization_and_keeps_the_error_code(): void
    {
        Http::fake(['partner.shopeemobile.com/*' => Http::response(['error' => 'error_auth', 'message' => 'SECRET'], 401)]);

        try {
            $this->refresh(false);
            $this->fail('A rejected refresh must throw');
        } catch (MarketplaceIntegrationException $exception) {
            $this->assertSame('HTTP_401', $exception->errorCode);
            $this->assertStringNotContainsString('SECRET', $exception->getMessage());
        }

        $row = DB::table('marketplace_oauth_connections')->first();
        $this->assertSame('reauthorize', $row->status);
        $this->assertSame('HTTP_401', $row->last_error_code);
    }

    public function test_a_store_without_a_refresh_token_must_be_reconnected(): void
    {
        DB::table('marketplace_oauth_connections')->update(['refresh_token_encrypted' => null]);
        Http::fake();

        try {
            $this->refresh();
            $this->fail('No refresh token');
        } catch (MarketplaceIntegrationException $exception) {
            $this->assertSame('REAUTHORIZE_REQUIRED', $exception->errorCode);
        }
        Http::assertNothingSent();
    }

    public function test_another_companys_store_is_refused(): void
    {
        $this->expectException(\DomainException::class);
        app(MarketplaceScope::class)->forCompany('B', fn () => app(TokenRefresher::class)->refresh('S1'));
    }
}
