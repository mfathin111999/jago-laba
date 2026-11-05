<?php

namespace Tests\Feature;

use App\Services\Marketplace\MarketplaceSyncCheckpointService;
use App\Services\Marketplace\MarketplaceSyncDispatchService;
use App\Services\Marketplace\MarketplaceSyncQueuePublisher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class MarketplaceQueueAtomicityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('queue.default', 'database');
        Config::set('queue.connections.database.connection', null);
        Config::set('queue.connections.database.table', 'jobs');
        Config::set('jago.sync.queue', 'marketplace');
        Config::set('jago.sync.page_size', 1);
        Config::set('jago.sync.page_size_max', 100);
        Config::set('jago.sync.max_pages', 20);
        Config::set('jago.sync.max_pages_max', 100);
        Config::set('jago.sync.lease_seconds', 45);

        Schema::create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('marketplace_stores', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_key');
            $table->timestamp('archived_at')->nullable();
        });

        Schema::create('marketplace_oauth_connections', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('store_id');
            $table->string('company_key');
            $table->string('platform');
            $table->string('status');
        });

        Schema::create('marketplace_sync_jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('connection_id');
            $table->string('company_key');
            $table->string('store_id');
            $table->string('platform');
            $table->string('job_type');
            $table->string('run_key');
            $table->string('status');
            $table->text('checkpoint');
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->dateTime('requested_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->unique(['connection_id', 'job_type', 'run_key']);
        });

        Schema::create('marketplace_orders', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('store_id');
        });

        DB::table('marketplace_stores')->insert([
            'id' => 'store-a',
            'company_key' => 'LOCAL-COMPANY',
        ]);
        DB::table('marketplace_oauth_connections')->insert([
            'store_id' => 'store-a',
            'company_key' => 'LOCAL-COMPANY',
            'platform' => 'Shopee',
            'status' => 'verified',
        ]);
    }

    public function test_initial_sync_row_and_queue_payload_commit_together(): void
    {
        $result = app(MarketplaceSyncDispatchService::class)->dispatchForStore(
            'LOCAL-COMPANY',
            'store-a',
            'manual',
            'atomic-run-1',
            'request-1',
        );

        $this->assertSame('queued', $result['status']);
        $this->assertSame(1, DB::table('marketplace_sync_jobs')->count());
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertStringContainsString('MarketplaceSyncJob', (string) DB::table('jobs')->value('payload'));
        $this->assertStringContainsString('atomic-run-1', (string) DB::table('jobs')->value('payload'));
    }

    public function test_initial_sync_rolls_back_when_queue_insert_fails(): void
    {
        $publisher = $this->createMock(MarketplaceSyncQueuePublisher::class);
        $publisher->expects($this->once())
            ->method('push')
            ->willThrowException(new RuntimeException('queue unavailable'));

        try {
            (new MarketplaceSyncDispatchService($publisher))->dispatchForStore(
                'LOCAL-COMPANY',
                'store-a',
                'manual',
                'atomic-run-failure',
                'request-failure',
            );
            $this->fail('Queue failure should abort the dispatch transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('queue unavailable', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('marketplace_sync_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_next_page_checkpoint_and_queue_payload_commit_together(): void
    {
        DB::table('marketplace_orders')->insert(['store_id' => 'store-a']);
        DB::table('marketplace_orders')->insert(['store_id' => 'store-a']);
        $jobId = $this->insertRunningSyncJob();

        $result = app(MarketplaceSyncCheckpointService::class)->processPage(
            'LOCAL-COMPANY',
            'store-a',
            1,
            $jobId,
            'atomic-pages',
            'request-pages',
            1,
        );

        $this->assertTrue($result['has_more']);
        $this->assertSame('queued', DB::table('marketplace_sync_jobs')->value('status'));
        $this->assertSame(2, json_decode((string) DB::table('marketplace_sync_jobs')->value('checkpoint'), true)['page']);
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_next_page_queue_failure_rolls_back_checkpoint_and_leaves_current_job_retryable(): void
    {
        DB::table('marketplace_orders')->insert(['store_id' => 'store-a']);
        DB::table('marketplace_orders')->insert(['store_id' => 'store-a']);
        $jobId = $this->insertRunningSyncJob('atomic-pages-failure');
        $publisher = $this->createMock(MarketplaceSyncQueuePublisher::class);
        $publisher->expects($this->once())
            ->method('push')
            ->willThrowException(new RuntimeException('next queue unavailable'));

        try {
            (new MarketplaceSyncCheckpointService($publisher))->processPage(
                'LOCAL-COMPANY',
                'store-a',
                1,
                $jobId,
                'atomic-pages-failure',
                'request-pages-failure',
                1,
            );
            $this->fail('Next-page queue failure should abort the checkpoint transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('next queue unavailable', $exception->getMessage());
        }

        $job = DB::table('marketplace_sync_jobs')->where('id', $jobId)->first();
        $checkpoint = json_decode((string) $job->checkpoint, true);
        $this->assertSame('queued', $job->status);
        $this->assertSame(1, $checkpoint['page']);
        $this->assertSame(1, $job->failure_count);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    private function insertRunningSyncJob(string $runKey = 'atomic-pages'): int
    {
        return (int) DB::table('marketplace_sync_jobs')->insertGetId([
            'connection_id' => 1,
            'company_key' => 'LOCAL-COMPANY',
            'store_id' => 'store-a',
            'platform' => 'Shopee',
            'job_type' => 'orders',
            'run_key' => $runKey,
            'status' => 'running',
            'checkpoint' => json_encode([
                'source' => 'sandbox-local-order-scan',
                'mode' => 'manual',
                'page' => 1,
                'page_size' => 1,
                'max_pages' => 20,
                'processed' => 0,
                'last_id' => null,
            ], JSON_THROW_ON_ERROR),
            'success_count' => 0,
            'failure_count' => 0,
            'requested_at' => now(),
            'started_at' => now()->subMinute(),
        ]);
    }
}
