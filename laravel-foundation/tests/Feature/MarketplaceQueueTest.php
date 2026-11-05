<?php

namespace Tests\Feature;

use App\Services\Marketplace\MarketplaceSyncCheckpointService;
use App\Services\Marketplace\MarketplaceSyncDispatchService;
use App\Jobs\MarketplaceSyncJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MarketplaceQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->makeQueueSchema();
        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => null,
            'jago.company_key' => 'LOCAL-COMPANY',
            'jago.sync.page_size' => 2,
            'jago.sync.page_size_max' => 10,
            'jago.sync.max_pages' => 5,
            'jago.sync.max_pages_max' => 10,
            'jago.sync.max_stores' => 20,
            'jago.sync.max_stores_max' => 20,
            'jago.sync.lease_seconds' => 45,
        ]);

        DB::table('marketplace_stores')->insert([
            'id' => 'STORE-001',
            'company_key' => 'LOCAL-COMPANY',
            'platform' => 'Shopee',
            'store_name' => 'Sandbox Store',
            'archived_at' => null,
        ]);
        DB::table('marketplace_oauth_connections')->insert([
            'id' => 1,
            'company_key' => 'LOCAL-COMPANY',
            'store_id' => 'STORE-001',
            'platform' => 'Shopee',
            'status' => 'verified',
        ]);
        DB::table('marketplace_orders')->insert([
            ['id' => 'ORDER-001', 'store_id' => 'STORE-001'],
            ['id' => 'ORDER-002', 'store_id' => 'STORE-001'],
            ['id' => 'ORDER-003', 'store_id' => 'STORE-001'],
            ['id' => 'ORDER-004', 'store_id' => 'STORE-001'],
            ['id' => 'ORDER-005', 'store_id' => 'STORE-001'],
        ]);
    }

    public function test_command_is_bounded_idempotent_and_dispatches_one_page_job(): void
    {
        Queue::fake();

        $this->assertSame(0, Artisan::call('marketplace:sync', [
            '--company' => 'LOCAL-COMPANY',
            '--store' => 'STORE-001',
            '--run-key' => 'cron-fixed-001',
            '--request-id' => 'request-fixed-001',
        ]));
        $this->assertSame(0, Artisan::call('marketplace:sync', [
            '--company' => 'LOCAL-COMPANY',
            '--store' => 'STORE-001',
            '--run-key' => 'cron-fixed-001',
            '--request-id' => 'request-fixed-001',
        ]));

        $this->assertSame(1, DB::table('marketplace_sync_jobs')->count());
        Queue::assertPushed(MarketplaceSyncJob::class, 1);
        $this->assertSame('cron-fixed-001', DB::table('marketplace_sync_jobs')->value('run_key'));
    }

    public function test_job_processes_bounded_pages_and_advances_checkpoint(): void
    {
        Queue::fake();
        Artisan::call('marketplace:sync', [
            '--company' => 'LOCAL-COMPANY',
            '--store' => 'STORE-001',
            '--run-key' => 'manual-pages-001',
        ]);

        $job = new MarketplaceSyncJob('LOCAL-COMPANY', 'STORE-001', 1, 1, 'manual-pages-001', 'request-pages-001');
        $job->handle(app(MarketplaceSyncCheckpointService::class));

        $row = DB::table('marketplace_sync_jobs')->first();
        $checkpoint = json_decode((string) $row->checkpoint, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('queued', $row->status);
        $this->assertSame(2, $row->success_count);
        $this->assertSame(2, $checkpoint['page']);
        $this->assertSame('ORDER-002', $checkpoint['last_id']);
        Queue::assertPushed(MarketplaceSyncJob::class, 2);
    }

    public function test_recent_overlap_is_skipped_and_stale_checkpoint_restarts(): void
    {
        Queue::fake();
        Artisan::call('marketplace:sync', [
            '--company' => 'LOCAL-COMPANY',
            '--store' => 'STORE-001',
            '--run-key' => 'restart-001',
        ]);
        DB::table('marketplace_sync_jobs')->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        $job = new MarketplaceSyncJob('LOCAL-COMPANY', 'STORE-001', 1, 1, 'restart-001', 'request-restart-001');
        $job->handle(app(MarketplaceSyncCheckpointService::class));
        $this->assertSame(0, DB::table('marketplace_sync_jobs')->value('success_count'));

        DB::table('marketplace_sync_jobs')->update(['started_at' => now()->subSeconds(60)]);
        $job->handle(app(MarketplaceSyncCheckpointService::class));
        $this->assertSame(2, DB::table('marketplace_sync_jobs')->value('success_count'));
    }

    public function test_failed_job_is_recorded_and_new_run_key_can_retry(): void
    {
        Queue::fake();
        Artisan::call('marketplace:sync', [
            '--company' => 'LOCAL-COMPANY',
            '--store' => 'STORE-001',
            '--run-key' => 'failed-001',
        ]);

        $job = new MarketplaceSyncJob('LOCAL-COMPANY', 'STORE-001', 1, 1, 'failed-001', 'request-failed-001');
        $job->failed(new \RuntimeException('sandbox failure'));
        $this->assertSame('failed', DB::table('marketplace_sync_jobs')->value('status'));
        $this->assertSame('QUEUE_FAILED', DB::table('marketplace_sync_jobs')->value('last_error_code'));

        $this->assertSame(0, Artisan::call('marketplace:sync', [
            '--company' => 'LOCAL-COMPANY',
            '--store' => 'STORE-001',
            '--run-key' => 'failed-001',
        ]));
        $this->assertSame(1, DB::table('marketplace_sync_jobs')->count());

        Artisan::call('marketplace:sync', [
            '--company' => 'LOCAL-COMPANY',
            '--store' => 'STORE-001',
            '--run-key' => 'failed-retry-002',
        ]);
        $this->assertSame(2, DB::table('marketplace_sync_jobs')->count());
    }

    public function test_database_failed_jobs_can_be_retried_after_the_source_recovers(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.retry_after' => 1,
        ]);
        $this->makeDatabaseQueueTables();

        app(MarketplaceSyncDispatchService::class)->dispatchForStore(
            'LOCAL-COMPANY',
            'STORE-001',
            'manual',
            'database-retry-001',
            'request-database-retry-001',
        );
        Schema::drop('marketplace_orders');

        $this->runOneQueueWorker();
        DB::table('jobs')->update(['available_at' => time()]);
        $this->runOneQueueWorker();

        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame('failed', DB::table('marketplace_sync_jobs')->value('status'));

        Schema::create('marketplace_orders', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('store_id');
        });
        DB::table('marketplace_orders')->insert([
            ['id' => 'ORDER-RECOVERED-001', 'store_id' => 'STORE-001'],
        ]);

        $this->assertSame(0, Artisan::call('queue:retry', ['id' => 'all', '--queue' => 'marketplace']));
        $this->runOneQueueWorker();

        $this->assertSame('succeeded', DB::table('marketplace_sync_jobs')->value('status'));
    }

    public function test_manual_browser_mode_requires_csrf_and_dispatches_for_owner(): void
    {
        Queue::fake();

        $this->postJson('/api/rpc.php', [
            'method' => 'prosesLoginBackend',
            'args' => ['owner', 'owner-secret'],
        ])->assertOk()->assertJsonPath('result.status', 'success');
        $this->withHeader('X-CSRF-TOKEN', csrf_token())
            ->postJson('/api/workspace.php', ['workspace' => 'marketplace'])
            ->assertOk();

        $this->withHeader('X-CSRF-TOKEN', 'invalid-token')
            ->postJson('/pilot/marketplace/sync', ['store_id' => 'STORE-001'])
            ->assertStatus(419)
            ->assertJsonPath('code', 'CSRF_INVALID');

        $response = $this->withHeaders([
            'X-CSRF-TOKEN' => csrf_token(),
            'X-Idempotency-Key' => 'browser-run-001',
            'X-Request-Id' => 'browser-request-001',
        ])->postJson('/pilot/marketplace/sync', ['store_id' => 'STORE-001']);

        $response->assertStatus(202)
            ->assertJsonPath('data.run_key', 'browser-run-001')
            ->assertJsonPath('data.request_id', 'browser-request-001');
        Queue::assertPushed(MarketplaceSyncJob::class, 1);
    }

    public function test_scheduler_and_job_contract_are_registered_without_daemon_services(): void
    {
        Artisan::call('schedule:list');
        $this->assertStringContainsString('marketplace:sync', Artisan::output());
        $this->assertSame(2, (new MarketplaceSyncJob('LOCAL-COMPANY', 'STORE-001', 1, 1, 'contract-001', 'request-contract-001'))->tries);
        $this->assertSame(35, (new MarketplaceSyncJob('LOCAL-COMPANY', 'STORE-001', 1, 1, 'contract-001', 'request-contract-001'))->timeout);
    }

    private function makeQueueSchema(): void
    {
        Schema::create('auth_users_v2', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('username')->unique();
            $table->string('name')->nullable();
            $table->text('password_hash');
            $table->string('role_id')->nullable();
            $table->string('status')->default('aktif');
        });
        DB::table('auth_users_v2')->insert([
            'username' => 'owner',
            'name' => 'Queue Owner',
            'password_hash' => password_hash('owner-secret', PASSWORD_BCRYPT),
            'role_id' => 'ROLE-OWNER_SUPER_ADMIN',
            'status' => 'aktif',
        ]);
        Schema::create('marketplace_stores', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_key');
            $table->string('platform');
            $table->string('store_name')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
        Schema::create('marketplace_oauth_connections', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
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
        Schema::create('marketplace_orders', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('store_id');
        });
    }

    private function makeDatabaseQueueTables(): void
    {
        Schema::create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    private function runOneQueueWorker(): void
    {
        $this->assertSame(0, Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'marketplace',
            '--once' => true,
            '--tries' => 2,
            '--timeout' => 35,
        ]));
    }
}
