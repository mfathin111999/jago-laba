<?php

namespace Tests\Feature;

use App\Auth\AuthenticatedUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MarketplaceIntegrationsPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('o', 32)), 'jago.oauth.state_ttl_seconds' => 600, 'queue.default' => 'database']);

        $this->createWorkspaceAccessTable();
        Schema::create('marketplace_stores', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_key');
            $table->string('platform');
            $table->string('store_name');
            $table->string('store_identifier')->nullable();
            $table->string('status')->nullable();
            $table->string('authorization_status')->nullable();
            $table->string('sync_mode')->nullable();
            $table->unsignedBigInteger('default_account_id')->nullable();
            $table->decimal('default_admin_fee_pct', 8, 2)->nullable();
            $table->decimal('default_free_shipping_pct', 8, 2)->nullable();
            $table->decimal('default_payment_fee_pct', 8, 2)->nullable();
            $table->integer('total_transactions')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('archived_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
        Schema::create('marketplace_oauth_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('company_key');
            $table->string('store_id');
            $table->string('platform');
            $table->string('status');
            $table->text('access_token_encrypted')->nullable();
            $table->text('refresh_token_encrypted')->nullable();
            $table->dateTime('authorized_at')->nullable();
            $table->dateTime('token_expires_at')->nullable();
            $table->dateTime('last_successful_sync_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        Schema::create('marketplace_sync_jobs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('connection_id');
            $table->string('company_key');
            $table->string('store_id');
            $table->string('platform');
            $table->string('job_type');
            $table->string('run_key');
            $table->string('status')->default('queued');
            $table->text('checkpoint')->nullable();
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->dateTime('requested_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->unique(['connection_id', 'job_type', 'run_key']);
        });
        Schema::create('marketplace_branding', function (Blueprint $table): void {
            $table->string('company_key', 120)->primary();
            $table->binary('logo_blob')->nullable();
            $table->string('logo_mime', 40)->nullable();
            $table->smallInteger('logo_size')->default(32);
            $table->string('logo_fit', 12)->default('contain');
            $table->string('logo_hash', 64)->nullable();
            $table->string('updated_by', 120);
            $table->dateTime('updated_at');
        });
        Schema::create('marketplace_catalog_variants', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('store_id');
            $table->string('external_product_id')->nullable();
            $table->decimal('price', 18, 2)->nullable();
            $table->integer('stock_qty')->nullable();
            $table->string('source_status')->nullable();
            $table->boolean('catalog_visible')->default(true);
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

        DB::table('marketplace_stores')->insert([
            ['id' => 'a', 'company_key' => 'A', 'platform' => 'Shopee', 'store_name' => 'Toko A', 'status' => 'Terhubung', 'authorization_status' => 'verified'],
            ['id' => 'b', 'company_key' => 'B', 'platform' => 'Shopee', 'store_name' => 'Toko B', 'status' => 'Terhubung', 'authorization_status' => 'verified'],
        ]);
        DB::table('marketplace_oauth_connections')->insert([
            ['company_key' => 'A', 'store_id' => 'a', 'platform' => 'Shopee', 'status' => 'verified', 'access_token_encrypted' => 'SECRET-ENC', 'refresh_token_encrypted' => 'SECRET-ENC-2', 'authorized_at' => '2026-10-01 00:00:00', 'last_successful_sync_at' => '2026-11-01 08:00:00'],
        ]);
        DB::table('workspace_user_access')->insert([['user_key' => 'u1', 'workspace' => 'marketplace']]);
        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');
    }

    private function loginAs(string $role): void
    {
        $this->actingAs(new AuthenticatedUser(['id' => 'u1', 'username' => 'u1', 'role_id' => $role, 'company_key' => 'A']), 'web');
        session(['_token' => 'tok', 'workspace' => 'marketplace']);
    }

    private function page(string $query = ''): TestResponse
    {
        $headers = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml'];
        if (is_file(public_path('build/manifest.json'))) {
            $headers['X-Inertia-Version'] = hash_file('xxh128', public_path('build/manifest.json'));
        }

        return $this->withHeaders($headers)->get('/marketplace/integrations'.$query);
    }

    public function test_the_page_shows_the_first_store_health_without_credentials(): void
    {
        $response = $this->page()->assertOk()
            ->assertJsonPath('component', 'Marketplace/Integrations')
            ->assertJsonPath('props.scope.store_id', 'a')
            ->assertJsonPath('props.health.connection_status', 'verified')
            ->assertJsonPath('props.health.platform', 'Shopee')
            ->assertJsonPath('props.can_manage', true)
            ->assertJsonPath('props.can_brand', true)
            ->assertJsonPath('props.branding.has_logo', false);

        $this->assertNotEmpty($response->json('props.health.resources'));
        $this->assertNotEmpty($response->json('props.platforms'));
        foreach (['SECRET-ENC', 'access_token', 'Toko B'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $response->getContent());
        }
    }

    public function test_the_company_logo_is_shared_with_every_page_without_its_bytes(): void
    {
        $this->page()->assertJsonPath('props.marketplaceBrand.has_logo', false);

        DB::table('marketplace_branding')->insert(['company_key' => 'A', 'logo_blob' => 'LOGO-BYTES-RAHASIA', 'logo_mime' => 'image/png', 'logo_size' => 44, 'logo_fit' => 'cover', 'logo_hash' => 'abc123', 'updated_by' => 'u1', 'updated_at' => now()]);

        $response = $this->page()->assertJsonPath('props.marketplaceBrand.has_logo', true)
            ->assertJsonPath('props.marketplaceBrand.logo_size', 32)
            ->assertJsonPath('props.marketplaceBrand.version', 'abc123');
        $this->assertStringNotContainsString('LOGO-BYTES-RAHASIA', $response->getContent());
    }

    public function test_another_companys_store_is_refused(): void
    {
        $this->page('?store_id=b')->assertForbidden();
    }

    public function test_a_reader_sees_the_page_but_no_action_flags(): void
    {
        $this->loginAs('ROLE-PEMBACA');
        $this->page('?store_id=a')->assertOk()
            ->assertJsonPath('props.can_manage', false)
            ->assertJsonPath('props.can_sync', false)
            ->assertJsonPath('props.can_brand', false);
    }

    public function test_the_provider_callback_return_lands_on_this_page(): void
    {
        $this->get('/marketplace.php?tab=integrasi&oauth_status=success&store_id=a')
            ->assertRedirect('/marketplace/integrations?store_id=a&oauth_status=success');

        $this->page('?store_id=a&oauth_status=error&oauth_message=Gagal')->assertOk()
            ->assertJsonPath('props.oauth.status', 'error')
            ->assertJsonPath('props.oauth.message', 'Gagal');
    }

    public function test_the_actions_used_by_the_page_work_over_json(): void
    {
        $headers = ['X-CSRF-TOKEN' => 'tok'];

        $this->withHeaders($headers)->postJson('/pilot/marketplace/oauth/start', ['platform' => 'Shopee', 'return_path' => 'marketplace/integrations'])
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.environment', 'sandbox');

        $this->withHeaders($headers)->postJson('/api/marketplace-oauth.php', ['action' => 'sync', 'store_id' => 'a'])
            ->assertOk()->assertJsonPath('success', true);
        $this->assertSame(1, DB::table('marketplace_sync_jobs')->where('store_id', 'a')->count());
    }
}
