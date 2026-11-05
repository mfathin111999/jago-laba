<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use App\Services\Marketplace\MarketplaceAutoSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MarketplaceStockAutoSyncTest extends TestCase
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
        Schema::create('marketplace_auto_sync', function (Blueprint $table): void {
            $table->string('store_id', 50);
            $table->string('company_key', 191);
            $table->string('mode', 20);
            $table->tinyInteger('enabled')->default(0);
            $table->dateTime('next_run_at')->nullable();
            $table->dateTime('last_success_at')->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->primary(['store_id', 'company_key', 'mode']);
        });
        Schema::create('marketplace_catalog_variants', function (Blueprint $table): void {
            $table->id();
            $table->string('store_id');
            $table->string('external_product_id');
            $table->string('external_variant_id');
            $table->boolean('catalog_visible')->default(true);
        });
        Schema::create('marketplace_sync_records', function (Blueprint $table): void {
            $table->id();
            $table->string('store_id');
            $table->string('resource_type');
            $table->string('external_id');
            $table->longText('payload_json')->nullable();
        });
        Schema::create('marketplace_stock_minimums', function (Blueprint $table): void {
            $table->string('store_id', 120);
            $table->string('product_id', 100);
            $table->string('variant_id', 100);
            $table->string('warehouse_id', 100)->default('');
            $table->decimal('minimum_qty', 16, 3);
            $table->string('changed_by', 120);
            $table->dateTime('changed_at');
            $table->primary(['store_id', 'product_id', 'variant_id', 'warehouse_id']);
        });
        Schema::create('marketplace_stock_minimum_audit', function (Blueprint $table): void {
            $table->id();
            $table->string('company_key', 120);
            $table->string('store_id', 120);
            $table->string('product_id', 100);
            $table->string('variant_id', 100);
            $table->string('warehouse_id', 100);
            $table->decimal('previous_qty', 16, 3)->nullable();
            $table->decimal('new_qty', 16, 3);
            $table->string('actor', 120);
            $table->dateTime('created_at');
        });

        DB::table('workspace_user_access')->insert([['user_key' => 'owner', 'workspace' => 'marketplace']]);
        DB::table('marketplace_stores')->insert([
            ['id' => 'a', 'company_key' => 'A', 'platform' => 'Shopee', 'store_name' => 'Toko A', 'archived_at' => null],
            ['id' => 'noauth', 'company_key' => 'A', 'platform' => 'Shopee', 'store_name' => 'Tanpa Otorisasi', 'archived_at' => null],
            ['id' => 'b', 'company_key' => 'B', 'platform' => 'Shopee', 'store_name' => 'Toko B', 'archived_at' => null],
        ]);
        DB::table('marketplace_oauth_connections')->insert([
            ['id' => 1, 'company_key' => 'A', 'store_id' => 'a', 'platform' => 'Shopee', 'status' => 'verified'],
            ['id' => 2, 'company_key' => 'B', 'store_id' => 'b', 'platform' => 'Shopee', 'status' => 'verified'],
        ]);
        DB::table('marketplace_catalog_variants')->insert([
            ['store_id' => 'a', 'external_product_id' => 'P1', 'external_variant_id' => 'V1', 'catalog_visible' => 1],
            ['store_id' => 'a', 'external_product_id' => 'P1', 'external_variant_id' => 'HIDDEN', 'catalog_visible' => 0],
        ]);
        DB::table('marketplace_sync_records')->insert([
            ['store_id' => 'a', 'resource_type' => 'product', 'external_id' => 'P1', 'payload_json' => json_encode(['skus' => [['id' => 'V1', 'inventory' => [['warehouse_id' => 'W1', 'quantity' => 4]]]]])],
        ]);

        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');
    }

    private function loginAs(string $role): void
    {
        $this->actingAs(new LegacyUser(['id' => 'owner', 'role_id' => $role, 'company_key' => 'A']), 'web');
        session(['_token' => 'tok', 'workspace' => 'marketplace']);
    }

    /** @param array<string, mixed> $payload */
    private function send(string $url, array $payload): TestResponse
    {
        return $this->withHeader('X-CSRF-TOKEN', 'tok')->postJson($url, $payload);
    }

    /** @return array<string, mixed> */
    private function minimum(array $overrides = []): array
    {
        return $overrides + ['store_id' => 'a', 'product_id' => 'P1', 'variant_id' => 'V1', 'warehouse_id' => '', 'minimum' => '5'];
    }

    public function test_minimum_is_saved_updated_and_audited_with_the_previous_value(): void
    {
        $this->send('/api/marketplace-stock.php', $this->minimum())->assertOk()->assertJsonPath('success', true);
        $this->send('/api/marketplace-stock.php', $this->minimum(['minimum' => '7.5']))->assertOk();
        $this->send('/api/marketplace-stock.php', $this->minimum(['warehouse_id' => 'W1', 'minimum' => '2']))->assertOk();

        $this->assertSame(2, DB::table('marketplace_stock_minimums')->count());
        $this->assertEquals(7.5, DB::table('marketplace_stock_minimums')->where('warehouse_id', '')->value('minimum_qty'));
        $audit = DB::table('marketplace_stock_minimum_audit')->where('warehouse_id', '')->orderBy('id')->get();
        $this->assertCount(2, $audit);
        $this->assertNull($audit[0]->previous_qty);
        $this->assertEquals(5, $audit[1]->previous_qty);
        $this->assertSame('A', $audit[0]->company_key);
    }

    public function test_minimum_rejects_bad_input_foreign_scope_and_insufficient_role(): void
    {
        $this->send('/api/marketplace-stock.php', $this->minimum(['minimum' => '-1']))->assertStatus(400);
        $this->send('/api/marketplace-stock.php', $this->minimum(['minimum' => '1,5']))->assertStatus(400);
        $this->send('/api/marketplace-stock.php', $this->minimum(['variant_id' => 'HIDDEN']))->assertForbidden();
        $this->send('/api/marketplace-stock.php', $this->minimum(['variant_id' => 'NOPE']))->assertForbidden();
        $this->send('/api/marketplace-stock.php', $this->minimum(['warehouse_id' => 'W-OTHER']))->assertForbidden();
        $this->send('/api/marketplace-stock.php', $this->minimum(['store_id' => 'b']))->assertForbidden();
        $this->withHeader('X-CSRF-TOKEN', 'wrong')->postJson('/api/marketplace-stock.php', $this->minimum())->assertStatus(419);

        $this->loginAs('ROLE-KEUANGAN');
        $this->send('/api/marketplace-stock.php', $this->minimum())->assertForbidden();
        $this->assertSame(0, DB::table('marketplace_stock_minimums')->count());
        $this->assertSame(0, DB::table('marketplace_stock_minimum_audit')->count());
    }

    public function test_auto_sync_states_toggle_and_authorization_rules(): void
    {
        $this->getJson('/api/marketplace-auto-sync.php?store_id=a')->assertOk()
            ->assertJsonPath('scheduler_available', true)
            ->assertJsonPath('states.status.enabled', false)
            ->assertJsonCount(5, 'states');

        $this->send('/api/marketplace-auto-sync.php', ['store_id' => 'a', 'mode' => 'status', 'enabled' => '1'])->assertOk()
            ->assertJsonPath('states.status.enabled', true);
        $this->send('/api/marketplace-auto-sync.php', ['store_id' => 'a', 'mode' => 'status', 'enabled' => '0'])->assertOk()
            ->assertJsonPath('states.status.enabled', false);

        $this->send('/api/marketplace-auto-sync.php', ['store_id' => 'noauth', 'mode' => 'status', 'enabled' => '1'])->assertForbidden()
            ->assertJsonPath('message', 'Otorisasi toko diperlukan sebelum mengaktifkan auto sync.');
        $this->send('/api/marketplace-auto-sync.php', ['store_id' => 'a', 'mode' => 'bogus', 'enabled' => '1'])->assertStatus(400);
        $this->send('/api/marketplace-auto-sync.php', ['store_id' => 'a', 'mode' => 'status', 'enabled' => 'yes'])->assertStatus(400);
        $this->send('/api/marketplace-auto-sync.php', ['store_id' => 'b', 'mode' => 'status', 'enabled' => '1'])->assertForbidden();
        $this->getJson('/api/marketplace-auto-sync.php?store_id=b')->assertForbidden();
        $this->withHeader('X-CSRF-TOKEN', 'wrong')->postJson('/api/marketplace-auto-sync.php', ['store_id' => 'a', 'mode' => 'status', 'enabled' => '1'])->assertStatus(419);

        $this->loginAs('ROLE-KEUANGAN');
        $this->getJson('/api/marketplace-auto-sync.php?store_id=a')->assertOk();
        $this->send('/api/marketplace-auto-sync.php', ['store_id' => 'a', 'mode' => 'new', 'enabled' => '1'])->assertForbidden();
    }

    public function test_tick_enqueues_one_run_per_due_schedule_without_overlap_and_learns_the_outcome(): void
    {
        $service = app(MarketplaceAutoSyncService::class);
        $service->set('A', 'a', 'new', true);

        $this->assertSame(1, $service->tick());
        $job = DB::table('marketplace_sync_jobs')->first();
        $this->assertStringStartsWith('auto-new-', $job->run_key);
        $this->assertSame('queued', $job->status);
        $this->assertSame(0, $service->tick(), 'schedule is no longer due right after enqueueing');

        // Due again while the previous run is still queued: never overlap.
        $past = now()->subMinute();
        DB::table('marketplace_auto_sync')->update(['next_run_at' => $past]);
        $this->assertSame(0, $service->tick());
        $this->assertSame(1, DB::table('marketplace_sync_jobs')->count());

        // Previous run succeeded: its finish time becomes last_success_at and a new run is enqueued.
        $finished = now()->subSeconds(90)->format('Y-m-d H:i:s');
        DB::table('marketplace_sync_jobs')->update(['status' => 'succeeded', 'finished_at' => $finished]);
        DB::table('marketplace_auto_sync')->update(['next_run_at' => $past]);
        $this->assertSame(1, $service->tick());
        $this->assertSame(2, DB::table('marketplace_sync_jobs')->count());
        $state = $service->states('A', 'a')['new'];
        $this->assertSame($finished, $state['last_success_at']);
        $this->assertNull($state['last_error_code']);

        // Previous run failed: the error code is kept and the retry is delayed by five minutes.
        DB::table('marketplace_sync_jobs')->where('status', 'queued')->update(['status' => 'failed', 'last_error_code' => 'SYNC_RETRYABLE']);
        DB::table('marketplace_auto_sync')->update(['next_run_at' => $past]);
        $this->assertSame(1, $service->tick());
        $state = $service->states('A', 'a')['new'];
        $this->assertSame('SYNC_RETRYABLE', $state['last_error_code']);
        $this->assertTrue(strtotime((string) $state['next_run_at']) > time() + 240);
    }

    public function test_tick_skips_archived_stores_and_flags_lost_authorization_without_enqueueing(): void
    {
        $service = app(MarketplaceAutoSyncService::class);
        $service->set('A', 'a', 'status', true);
        DB::table('marketplace_oauth_connections')->where('store_id', 'a')->update(['status' => 'revoked']);

        $this->assertSame(0, $service->tick());
        $this->assertSame(0, DB::table('marketplace_sync_jobs')->count());
        $this->assertSame('REAUTHORIZE_REQUIRED', $service->states('A', 'a')['status']['last_error_code']);

        DB::table('marketplace_auto_sync')->update(['next_run_at' => now()->subMinute()]);
        DB::table('marketplace_stores')->where('id', 'a')->update(['archived_at' => now()]);
        $this->assertSame(0, $service->tick());
        $this->assertSame('REAUTHORIZE_REQUIRED', $service->states('A', 'a')['status']['last_error_code']);
    }

    public function test_command_enqueues_due_runs_and_browser_pulse_is_scoped_to_the_company(): void
    {
        $service = app(MarketplaceAutoSyncService::class);
        $service->set('A', 'a', 'new', true);
        $service->set('B', 'b', 'new', true);

        $this->send('/api/marketplace-browser-pulse.php', [])->assertOk()->assertJsonPath('mode', 'browser');
        $this->assertSame(['a'], DB::table('marketplace_sync_jobs')->pluck('store_id')->all());

        $this->assertSame(0, Artisan::call('marketplace:auto-sync'));
        $this->assertStringContainsString('Enqueued 1 auto-sync run(s).', Artisan::output());
        $this->assertSame(['a', 'b'], DB::table('marketplace_sync_jobs')->orderBy('store_id')->pluck('store_id')->all());

        $this->withHeader('X-CSRF-TOKEN', 'wrong')->postJson('/api/marketplace-browser-pulse.php', [])->assertStatus(419);
    }
}
