<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MarketplaceSyncApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['queue.default' => 'database']);

        Schema::create('workspace_user_access', function (Blueprint $table): void {
            $table->string('user_key');
            $table->string('workspace');
        });
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
            $table->string('external_store_name')->nullable();
            $table->dateTime('last_successful_sync_at')->nullable();
        });
        Schema::create('marketplace_orders', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('store_id');
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

        DB::table('workspace_user_access')->insert([['user_key' => 'owner', 'workspace' => 'marketplace']]);
        DB::table('marketplace_stores')->insert([
            ['id' => 'a', 'company_key' => 'A', 'platform' => 'Shopee', 'store_name' => 'Toko A', 'archived_at' => null],
            ['id' => 'arch', 'company_key' => 'A', 'platform' => 'Shopee', 'store_name' => 'Arsip', 'archived_at' => '2026-01-01 00:00:00'],
            ['id' => 'lazada', 'company_key' => 'A', 'platform' => 'Lazada', 'store_name' => 'Lazada', 'archived_at' => null],
            ['id' => 'b', 'company_key' => 'B', 'platform' => 'Shopee', 'store_name' => 'Toko B', 'archived_at' => null],
        ]);
        DB::table('marketplace_oauth_connections')->insert([
            ['id' => 1, 'company_key' => 'A', 'store_id' => 'a', 'platform' => 'Shopee', 'status' => 'verified', 'external_store_name' => 'Ext A'],
            ['id' => 2, 'company_key' => 'A', 'store_id' => 'arch', 'platform' => 'Shopee', 'status' => 'verified', 'external_store_name' => null],
            ['id' => 3, 'company_key' => 'A', 'store_id' => 'lazada', 'platform' => 'Lazada', 'status' => 'verified', 'external_store_name' => null],
            ['id' => 4, 'company_key' => 'B', 'store_id' => 'b', 'platform' => 'Shopee', 'status' => 'verified', 'external_store_name' => null],
        ]);
        DB::table('marketplace_orders')->insert([['id' => 'O1', 'store_id' => 'a'], ['id' => 'O2', 'store_id' => 'a'], ['id' => 'O3', 'store_id' => 'b']]);
    }

    private function loginAs(string $role): void
    {
        $this->actingAs(new LegacyUser(['id' => 'owner', 'role_id' => $role, 'company_key' => 'A']), 'web');
        session(['_token' => 'tok', 'workspace' => 'marketplace']);
    }

    /** @param array<string, mixed> $payload */
    private function syncRequest(array $payload): TestResponse
    {
        return $this->withHeader('X-CSRF-TOKEN', 'tok')->postJson('/api/marketplace-sync.php', $payload);
    }

    public function test_preview_lists_only_eligible_stores_of_the_company_with_order_counts(): void
    {
        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');

        $response = $this->getJson('/api/marketplace-sync.php?action=preview')->assertOk();
        $stores = $response->json('data.stores');

        $this->assertSame(['a'], array_column($stores, 'id'));
        $this->assertSame(2, $stores[0]['order_count']);
        $this->assertSame('calendar_month', $stores[0]['window']['policy']);
        $this->assertLessThan($stores[0]['window']['window_end'], $stores[0]['window']['window_start']);

        $this->getJson('/api/marketplace-sync.php?action=preview&store_id=b')->assertForbidden();
    }

    public function test_start_enqueues_one_run_and_does_not_duplicate_while_pending(): void
    {
        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');

        $first = $this->syncRequest(['action' => 'start', 'store_id' => 'a'])->assertOk();
        $first->assertJsonPath('data.pending', 1)->assertJsonPath('data.failed', 0)->assertJsonPath('data.can_close', true)
            ->assertJsonPath('data.jobs.0.status', 'queued');

        $this->syncRequest(['action' => 'background', 'store_id' => 'a'])->assertOk()->assertJsonPath('data.pending', 1);
        $this->assertSame(1, DB::table('marketplace_sync_jobs')->count());

        $this->syncRequest(['action' => 'step', 'store_id' => 'a'])->assertOk()->assertJsonCount(1, 'data.jobs');
        $this->getJson('/api/marketplace-sync.php?action=status&store_id=a')->assertOk()->assertJsonPath('data.pending', 1);

        DB::table('marketplace_sync_jobs')->update(['status' => 'failed', 'failure_count' => 1, 'last_error_code' => 'SYNC_RETRYABLE']);
        $this->syncRequest(['action' => 'start', 'store_id' => 'a'])->assertOk()
            ->assertJsonPath('data.pending', 1)->assertJsonPath('data.failed', 1);
        $this->assertSame(2, DB::table('marketplace_sync_jobs')->count());
    }

    public function test_mutating_actions_require_post_and_csrf(): void
    {
        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');

        $this->getJson('/api/marketplace-sync.php?action=start&store_id=a')->assertStatus(400);
        $this->withHeader('X-CSRF-TOKEN', 'wrong')->postJson('/api/marketplace-sync.php', ['action' => 'start', 'store_id' => 'a'])->assertStatus(419);
        $this->assertSame(0, DB::table('marketplace_sync_jobs')->count());
    }

    public function test_scope_and_role_are_enforced_and_unknown_actions_rejected(): void
    {
        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');
        $this->syncRequest(['action' => 'start', 'store_id' => 'b'])->assertForbidden();
        $this->syncRequest(['action' => 'start', 'store_id' => 'arch'])->assertForbidden();
        $this->syncRequest(['action' => 'start'])->assertForbidden();
        $this->syncRequest(['action' => 'bogus', 'store_id' => 'a'])->assertStatus(400)->assertJsonPath('message', 'Aksi tidak dikenal.');

        $this->loginAs('ROLE-OPERASIONAL');
        $this->syncRequest(['action' => 'status', 'store_id' => 'a'])->assertForbidden();
        $this->assertSame(0, DB::table('marketplace_sync_jobs')->count());
    }

    public function test_catalog_actions_report_that_the_connector_is_not_active(): void
    {
        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');

        foreach (['catalog', 'catalog_status'] as $action) {
            $this->syncRequest(['action' => $action, 'store_id' => 'a'])
                ->assertStatus(400)
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', 'Sinkronisasi katalog belum tersedia: konektor resmi marketplace belum aktif.');
        }
        $this->assertSame(0, DB::table('marketplace_sync_jobs')->count());
    }

    public function test_price_endpoint_validates_the_request_and_never_reaches_a_provider(): void
    {
        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');

        foreach (['prepare', 'save'] as $action) {
            $this->syncRequestTo('/api/marketplace-price.php', ['action' => $action, 'store_id' => 'a', 'product_id' => 'P1'])
                ->assertStatus(503)
                ->assertJsonPath('error_code', 'PROVIDER_CONNECTOR_INACTIVE');
        }

        $this->syncRequestTo('/api/marketplace-price.php', ['action' => 'bogus', 'store_id' => 'a'])->assertStatus(400);
        $this->syncRequestTo('/api/marketplace-price.php', ['action' => 'save', 'store_id' => 'b'])->assertForbidden();
        $this->withHeader('X-CSRF-TOKEN', 'wrong')->postJson('/api/marketplace-price.php', ['action' => 'save', 'store_id' => 'a'])->assertStatus(419);

        $this->loginAs('ROLE-PEMBACA');
        $this->syncRequestTo('/api/marketplace-price.php', ['action' => 'prepare', 'store_id' => 'a'])->assertForbidden();
    }

    /** @param array<string, mixed> $payload */
    private function syncRequestTo(string $url, array $payload): TestResponse
    {
        return $this->withHeader('X-CSRF-TOKEN', 'tok')->postJson($url, $payload);
    }
}
