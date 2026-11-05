<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MarketplaceIntegrationStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['queue.default' => 'database']);

        $this->createWorkspaceAccessTable();
        Schema::create('marketplace_stores', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_key');
            $table->string('platform');
            $table->string('store_name');
            $table->timestamp('archived_at')->nullable();
        });
        Schema::create('marketplace_oauth_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('company_key');
            $table->string('store_id');
            $table->string('platform');
            $table->string('status');
            $table->dateTime('authorized_at')->nullable();
            $table->dateTime('token_expires_at')->nullable();
            $table->dateTime('last_successful_sync_at')->nullable();
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
        Schema::create('marketplace_sync_records', function (Blueprint $table): void {
            $table->id();
            $table->string('store_id');
            $table->string('resource_type');
            $table->string('external_id')->nullable();
            $table->dateTime('fetched_at')->nullable();
        });
        Schema::create('marketplace_catalog_variants', function (Blueprint $table): void {
            $table->id();
            $table->string('store_id');
            $table->string('external_product_id');
            $table->string('external_variant_id');
            $table->decimal('price', 16, 2)->nullable();
            $table->decimal('stock_qty', 16, 3)->nullable();
            $table->string('source_status')->default('ACTIVATE');
            $table->boolean('catalog_visible')->default(true);
        });
        Schema::create('marketplace_api_capabilities', function (Blueprint $table): void {
            $table->unsignedBigInteger('connection_id');
            $table->string('resource', 30);
            $table->string('state', 30);
            $table->dateTime('last_verified_at')->nullable();
            $table->dateTime('last_attempt_at');
            $table->string('last_error_code', 100)->nullable();
            $table->primary(['connection_id', 'resource']);
        });
        Schema::create('marketplace_integration_bindings', function (Blueprint $table): void {
            $table->unsignedBigInteger('connection_id')->primary();
            $table->string('environment', 20);
            $table->char('context_hash', 64);
            $table->dateTime('verified_at');
        });
        Schema::create('marketplace_shipping_document_audit', function (Blueprint $table): void {
            $table->id();
            $table->string('store_id');
            $table->string('company_key');
            $table->string('event_type');
            $table->string('error_code')->nullable();
            $table->dateTime('created_at');
        });

        DB::table('workspace_user_access')->insert([['user_key' => 'owner', 'workspace' => 'marketplace']]);
        DB::table('marketplace_stores')->insert([
            ['id' => 'tt', 'company_key' => 'A', 'platform' => 'TikTok Shop', 'store_name' => 'TikTok A', 'archived_at' => null],
            ['id' => 'sp', 'company_key' => 'A', 'platform' => 'Shopee', 'store_name' => 'Shopee A', 'archived_at' => null],
            ['id' => 'none', 'company_key' => 'A', 'platform' => 'Shopee', 'store_name' => 'Tanpa Koneksi', 'archived_at' => null],
            ['id' => 'foreign', 'company_key' => 'B', 'platform' => 'Shopee', 'store_name' => 'B', 'archived_at' => null],
        ]);
        DB::table('marketplace_oauth_connections')->insert([
            ['id' => 1, 'company_key' => 'A', 'store_id' => 'tt', 'platform' => 'TikTok Shop', 'status' => 'verified', 'authorized_at' => '2026-10-01 00:00:00', 'token_expires_at' => null, 'last_successful_sync_at' => '2026-11-01 10:00:00'],
            ['id' => 2, 'company_key' => 'A', 'store_id' => 'sp', 'platform' => 'Shopee', 'status' => 'revoked', 'authorized_at' => '2026-10-01 00:00:00', 'token_expires_at' => '2020-01-01 00:00:00', 'last_successful_sync_at' => null],
        ]);
        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');
    }

    private function loginAs(string $role): void
    {
        $this->actingAs(new LegacyUser(['id' => 'owner', 'role_id' => $role, 'company_key' => 'A']), 'web');
        session(['_token' => 'tok', 'workspace' => 'marketplace']);
    }

    /** @param list<array<string, mixed>> $jobs */
    private function seedJobs(array $jobs): void
    {
        foreach ($jobs as $job) {
            DB::table('marketplace_sync_jobs')->insert($job);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function resources(string $store): array
    {
        $data = $this->getJson('/api/marketplace-integration-status.php?store_id='.$store)->assertOk()->json('data.resources');

        return array_column($data, null, 'key');
    }

    private function retry(array $payload): TestResponse
    {
        return $this->withHeader('X-CSRF-TOKEN', 'tok')->postJson('/api/marketplace-integration-status.php', $payload);
    }

    public function test_evidence_is_derived_from_synced_data_proofs_and_platform_support(): void
    {
        DB::table('marketplace_sync_records')->insert([
            ['store_id' => 'tt', 'resource_type' => 'product', 'external_id' => 'P1', 'fetched_at' => '2026-11-01 09:00:00'],
            ['store_id' => 'tt', 'resource_type' => 'order', 'external_id' => 'O1', 'fetched_at' => '2026-09-01 09:00:00'],
        ]);
        DB::table('marketplace_catalog_variants')->insert([
            ['store_id' => 'tt', 'external_product_id' => 'P1', 'external_variant_id' => 'V1', 'price' => 1000, 'stock_qty' => null],
            ['store_id' => 'tt', 'external_product_id' => 'P1', 'external_variant_id' => 'V2', 'price' => 2000, 'stock_qty' => 3],
        ]);
        DB::table('marketplace_integration_bindings')->insert(['connection_id' => 1, 'environment' => 'sandbox', 'context_hash' => str_repeat('a', 64), 'verified_at' => now()]);

        $response = $this->getJson('/api/marketplace-integration-status.php?store_id=tt')->assertOk();
        $response->assertJsonPath('data.connection_label', 'Terhubung')
            ->assertJsonPath('data.environment_label', 'Sandbox')
            ->assertJsonPath('data.metadata_ready', true)
            ->assertJsonPath('data.can_manage', true)
            ->assertJsonPath('data.counts.products', 1)
            ->assertJsonPath('data.counts.variants', 2)
            ->assertJsonPath('data.counts.prices', 2)
            ->assertJsonPath('data.counts.stock', 1)
            ->assertJsonPath('data.webhook_active', false);
        $resources = array_column($response->json('data.resources'), null, 'key');

        $this->assertSame('available', $resources['products']['state']);
        $this->assertSame('available', $resources['prices']['state']);
        $this->assertSame('available', $resources['stock']['state']);
        $this->assertSame('unverified', $resources['orders']['state'], 'order data older than the authorization proves nothing');
        $this->assertSame('unsupported', $resources['withdrawals']['state']);
        $this->assertSame('unverified', $resources['shipping']['state']);
        $this->assertSame('not_started', $resources['orders']['sync_status']);

        $this->assertSame('unsupported', $this->resources('sp')['shipping']['state'], 'Shopee has no shipping documents');
    }

    public function test_recorded_capability_and_failed_job_override_inferred_state(): void
    {
        DB::table('marketplace_api_capabilities')->insert([
            'connection_id' => 1, 'resource' => 'orders', 'state' => 'permission', 'last_verified_at' => null,
            'last_attempt_at' => '2026-11-01 08:00:00', 'last_error_code' => 'HTTP_403',
        ]);
        $this->seedJobs([
            ['id' => 10, 'connection_id' => 1, 'company_key' => 'A', 'store_id' => 'tt', 'platform' => 'TikTok Shop', 'job_type' => 'products', 'run_key' => 'r1', 'status' => 'failed', 'last_error_code' => 'HTTP_429', 'success_count' => 4, 'requested_at' => '2026-11-01 07:00:00'],
            ['id' => 11, 'connection_id' => 1, 'company_key' => 'A', 'store_id' => 'tt', 'platform' => 'TikTok Shop', 'job_type' => 'transactions', 'run_key' => 'r2', 'status' => 'failed', 'last_error_code' => 'HTTP_401', 'requested_at' => '2026-11-01 07:00:00'],
            ['id' => 12, 'connection_id' => 1, 'company_key' => 'A', 'store_id' => 'tt', 'platform' => 'TikTok Shop', 'job_type' => 'returns', 'run_key' => 'r3', 'status' => 'failed', 'last_error_code' => 'HTTP_401', 'requested_at' => '2026-09-01 07:00:00'],
        ]);

        $resources = $this->resources('tt');

        $this->assertSame('permission', $resources['orders']['state']);
        $this->assertSame('HTTP_403', $resources['orders']['error_code']);
        $this->assertSame('temporary', $resources['products']['state'], 'rate limit is a temporary failure');
        $this->assertSame(10, $resources['products']['retry_job_id']);
        $this->assertSame(4, $resources['products']['processed']);
        $this->assertSame('reauthorize', $resources['finance']['state']);
        $this->assertSame('unverified', $resources['returns']['state'], 'a failure from before the current authorization is ignored');
    }

    public function test_unverified_connection_and_missing_connection_require_reauthorization(): void
    {
        $this->getJson('/api/marketplace-integration-status.php?store_id=sp')->assertOk()
            ->assertJsonPath('data.connection_status', 'revoked')
            ->assertJsonPath('data.connection_label', 'Belum terhubung')
            ->assertJsonPath('data.token_expired', true);
        $this->assertSame('reauthorize', $this->resources('sp')['orders']['state']);
        $this->assertSame('unsupported', $this->resources('sp')['withdrawals']['state']);

        $this->getJson('/api/marketplace-integration-status.php?store_id=none')->assertOk()
            ->assertJsonPath('data.connection_status', 'missing')
            ->assertJsonPath('data.environment', 'unspecified');
        $this->assertSame('reauthorize', $this->resources('none')['products']['state']);
    }

    public function test_shipping_document_audit_is_independent_evidence(): void
    {
        DB::table('marketplace_shipping_document_audit')->insert([
            ['store_id' => 'tt', 'company_key' => 'A', 'event_type' => 'prepared', 'error_code' => null, 'created_at' => '2026-10-15 00:00:00'],
        ]);
        $this->assertSame('available', $this->resources('tt')['shipping']['state']);

        DB::table('marketplace_shipping_document_audit')->insert([
            ['store_id' => 'tt', 'company_key' => 'A', 'event_type' => 'failed', 'error_code' => 'HTTP_403', 'created_at' => '2026-10-16 00:00:00'],
        ]);
        $this->assertSame('permission', $this->resources('tt')['shipping']['state']);
    }

    public function test_status_is_scoped_and_read_only_for_non_admins(): void
    {
        $this->getJson('/api/marketplace-integration-status.php?store_id=foreign')->assertForbidden();
        $this->getJson('/api/marketplace-integration-status.php')->assertForbidden();

        $this->loginAs('ROLE-KEUANGAN');
        $this->getJson('/api/marketplace-integration-status.php?store_id=tt')->assertOk()->assertJsonPath('data.can_manage', false);
        $this->retry(['store_id' => 'tt', 'action' => 'retry', 'job_id' => 1])->assertForbidden();
    }

    public function test_retry_enqueues_a_new_run_only_for_a_failed_job_when_nothing_is_pending(): void
    {
        $this->seedJobs([
            ['id' => 20, 'connection_id' => 1, 'company_key' => 'A', 'store_id' => 'tt', 'platform' => 'TikTok Shop', 'job_type' => 'orders', 'run_key' => 'old', 'status' => 'failed', 'last_error_code' => 'HTTP_500', 'requested_at' => '2026-11-01 00:00:00'],
            ['id' => 21, 'connection_id' => 1, 'company_key' => 'A', 'store_id' => 'tt', 'platform' => 'TikTok Shop', 'job_type' => 'products', 'run_key' => 'ok', 'status' => 'succeeded', 'requested_at' => '2026-11-01 00:00:00'],
        ]);

        $this->retry(['store_id' => 'tt', 'action' => 'retry', 'job_id' => 21])->assertForbidden();
        $this->retry(['store_id' => 'tt', 'action' => 'retry', 'job_id' => 999])->assertForbidden();
        $this->retry(['store_id' => 'tt', 'action' => 'bogus', 'job_id' => 20])->assertStatus(400);
        $this->withHeader('X-CSRF-TOKEN', 'wrong')->postJson('/api/marketplace-integration-status.php', ['store_id' => 'tt', 'action' => 'retry', 'job_id' => 20])->assertStatus(419);
        $this->assertSame(2, DB::table('marketplace_sync_jobs')->count());

        $this->retry(['store_id' => 'tt', 'action' => 'retry', 'job_id' => 20])->assertOk()->assertJsonPath('success', true);
        $this->assertSame(3, DB::table('marketplace_sync_jobs')->count());
        $this->assertStringStartsWith('retry-20-', DB::table('marketplace_sync_jobs')->orderByDesc('id')->value('run_key'));

        $this->retry(['store_id' => 'tt', 'action' => 'retry', 'job_id' => 20])->assertForbidden()
            ->assertJsonPath('message', 'Sinkronisasi toko masih berjalan. Coba ulang sumber yang gagal setelah proses selesai.');
        $this->assertSame(3, DB::table('marketplace_sync_jobs')->count());
    }
}
