<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use App\Pilots\Marketplace\MarketplaceStoreDeletionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MarketplaceStoreDeletionTest extends TestCase
{
    private string $ledgerDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerDir = sys_get_temp_dir().'/jl-ledger-'.bin2hex(random_bytes(4));
        config(['jago.store_deletion.ledger_dir' => $this->ledgerDir]);

        Schema::create('workspace_user_access', function (Blueprint $table): void {
            $table->string('user_key');
            $table->string('workspace');
        });
        Schema::create('system_audit_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            foreach (['event_id', 'event_time', 'user_id', 'username', 'module', 'action', 'record_id', 'reference_id', 'before_json', 'after_json', 'status', 'notes'] as $column) {
                $table->text($column)->nullable();
            }
        });
        Schema::create('marketplace_stores', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_key');
            $table->string('platform');
            $table->string('store_name');
            $table->string('store_identifier')->nullable();
            $table->string('status')->default('Terhubung');
            $table->string('authorization_status')->nullable();
            $table->string('api_access_token')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('archived_by')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
        Schema::create('marketplace_orders', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('store_id');
        });
        Schema::create('marketplace_order_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('order_id');
        });
        Schema::create('marketplace_expenses', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('store_id');
        });
        Schema::create('marketplace_auto_sync', function (Blueprint $table): void {
            $table->string('store_id');
            $table->boolean('enabled')->default(1);
        });
        Schema::create('marketplace_sync_jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('store_id');
            $table->string('status')->default('queued');
            $table->timestamp('finished_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->string('lease_token')->nullable();
            $table->timestamp('lease_until')->nullable();
        });

        DB::table('workspace_user_access')->insert([
            ['user_key' => 'owner', 'workspace' => 'marketplace'],
        ]);
        DB::table('marketplace_stores')->insert([
            ['id' => 'a', 'company_key' => 'A', 'platform' => 'Shopee', 'store_name' => 'Toko A', 'store_identifier' => 'EXT-A'],
            ['id' => 'a2', 'company_key' => 'A', 'platform' => 'Shopee', 'store_name' => 'Toko A2', 'store_identifier' => 'EXT-A2'],
            ['id' => 'b', 'company_key' => 'B', 'platform' => 'Shopee', 'store_name' => 'Toko B', 'store_identifier' => 'EXT-B'],
        ]);
        DB::table('marketplace_orders')->insert([['id' => 'O1', 'store_id' => 'a'], ['id' => 'O2', 'store_id' => 'a'], ['id' => 'O3', 'store_id' => 'a2']]);
        DB::table('marketplace_order_items')->insert([['order_id' => 'O1'], ['order_id' => 'O1'], ['order_id' => 'O2'], ['order_id' => 'O3']]);
        DB::table('marketplace_expenses')->insert([['store_id' => 'a'], ['store_id' => 'a2']]);
        DB::table('marketplace_auto_sync')->insert([['store_id' => 'a', 'enabled' => 1]]);
        DB::table('marketplace_sync_jobs')->insert([['store_id' => 'a', 'status' => 'queued']]);

        $this->service()->install();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->ledgerDir);
        parent::tearDown();
    }

    private function service(): MarketplaceStoreDeletionService
    {
        return app(MarketplaceStoreDeletionService::class);
    }

    private function loginAs(string $role): void
    {
        $this->actingAs(new LegacyUser(['id' => 'owner', 'role_id' => $role, 'company_key' => 'A']), 'web');
        session(['_token' => 'tok', 'workspace' => 'marketplace']);
    }

    /** @param array<string, mixed> $payload */
    private function deletionRequest(array $payload): TestResponse
    {
        return $this->withHeader('X-CSRF-TOKEN', 'tok')->postJson('/api/marketplace-store-deletion.php', $payload);
    }

    public function test_preview_counts_only_the_requested_store_and_company_scope_is_enforced(): void
    {
        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');

        $this->deletionRequest(['action' => 'preview', 'store_id' => 'a'])
            ->assertOk()
            ->assertJsonPath('data.counts.marketplace_orders', 2)
            ->assertJsonPath('data.counts.marketplace_order_items', 3)
            ->assertJsonPath('data.counts.marketplace_expenses', 1)
            ->assertJsonPath('data.environment', 'Live');

        $this->deletionRequest(['action' => 'preview', 'store_id' => 'b'])
            ->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_only_owner_can_request_and_csrf_is_required(): void
    {
        $this->loginAs('ROLE-ADMINISTRATOR');
        $this->deletionRequest(['action' => 'list'])->assertForbidden();

        $this->loginAs('ROLE-PEMBACA');
        $this->deletionRequest(['action' => 'list'])->assertForbidden();

        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');
        $this->withHeader('X-CSRF-TOKEN', 'wrong')->postJson('/api/marketplace-store-deletion.php', ['action' => 'list'])->assertStatus(419);
    }

    public function test_wrong_confirmation_name_is_rejected_without_changes(): void
    {
        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');

        $this->deletionRequest(['action' => 'request', 'store_id' => 'a', 'confirmation' => 'Toko Salah'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Nama konfirmasi harus sama dengan nama toko.');

        $this->assertSame(0, DB::table('marketplace_store_deletions')->count());
        $this->assertNull(DB::table('marketplace_stores')->where('id', 'a')->value('archived_at'));
    }

    public function test_request_erases_only_that_store_in_batches_and_records_audit_and_ledger(): void
    {
        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');

        // The request runs a few bounded batches inline; the scheduled worker finishes the rest.
        $this->deletionRequest(['action' => 'request', 'store_id' => 'a', 'confirmation' => 'Toko A'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'running');
        $this->assertNotNull(DB::table('marketplace_stores')->where('id', 'a')->first());

        $this->assertSame(0, Artisan::call('marketplace:store-deletion', ['--pending' => true]));

        $this->assertNull(DB::table('marketplace_stores')->where('id', 'a')->first());
        $this->assertSame(0, DB::table('marketplace_orders')->where('store_id', 'a')->count());
        $this->assertSame(0, DB::table('marketplace_expenses')->where('store_id', 'a')->count());
        $this->assertSame(0, DB::table('marketplace_sync_jobs')->where('store_id', 'a')->count());

        $this->assertSame(1, DB::table('marketplace_orders')->where('store_id', 'a2')->count());
        $this->assertSame(1, DB::table('marketplace_order_items')->where('order_id', 'O3')->count());
        $this->assertSame(1, DB::table('marketplace_expenses')->where('store_id', 'a2')->count());
        $this->assertNotNull(DB::table('marketplace_stores')->where('id', 'a2')->first());
        $this->assertNotNull(DB::table('marketplace_stores')->where('id', 'b')->first());

        $this->assertDatabaseHas('system_audit_logs', ['action' => 'REQUEST_STORE_DELETION', 'record_id' => 'a']);
        $this->assertCount(1, File::files($this->ledgerDir));
        $this->assertSame(2, $this->service()->status('A', 'a')['counts']['marketplace_orders']);

        $this->deletionRequest(['action' => 'request', 'store_id' => 'a', 'confirmation' => 'Toko A'])
            ->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame(1, DB::table('marketplace_store_deletions')->count());
    }

    public function test_request_disconnects_and_cancels_work_before_erasing(): void
    {
        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');

        $this->service()->request(auth('web')->user(), 'A', 'a', 'Toko A');

        $this->assertSame(0, (int) DB::table('marketplace_auto_sync')->where('store_id', 'a')->value('enabled'));
        $this->assertSame('pending', $this->service()->status('A', 'a')['status']);
        $this->assertSame('Terputus', DB::table('marketplace_stores')->where('id', 'a')->value('status'));
        $this->assertNotNull(DB::table('marketplace_stores')->where('id', 'a')->value('archived_at'));
        $this->assertTrue($this->service()->blocked('a'));
        $this->assertSame(['a'], $this->service()->unfinishedStoreIds());

        while (! $this->service()->step('a')) {
        }

        $this->assertSame([], $this->service()->unfinishedStoreIds());
    }

    public function test_worker_command_finishes_pending_deletions(): void
    {
        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');
        $this->service()->request(auth('web')->user(), 'A', 'a', 'Toko A');

        $this->assertSame(0, Artisan::call('marketplace:store-deletion', ['--pending' => true]));

        $this->assertSame('completed', $this->service()->status('A', 'a')['status']);
        $this->assertNull(DB::table('marketplace_stores')->where('id', 'a')->first());
    }

    public function test_unknown_store_scoped_table_blocks_deletion_until_audited(): void
    {
        Schema::create('marketplace_new_feature', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('store_id');
        });
        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');

        $this->deletionRequest(['action' => 'request', 'store_id' => 'a', 'confirmation' => 'Toko A'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Relasi toko baru perlu diaudit sebelum penghapusan: marketplace_new_feature');
        $this->assertNull(DB::table('marketplace_stores')->where('id', 'a')->value('archived_at'));
    }

    public function test_endpoint_reports_missing_schema_instead_of_installing_it(): void
    {
        Schema::drop('marketplace_store_deletions');
        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');

        $this->deletionRequest(['action' => 'list'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Fitur menunggu migrasi penghapusan toko oleh administrator server.');
        $this->assertFalse(Schema::hasTable('marketplace_store_deletions'));
    }

    public function test_authorization_guard_blocks_reconnecting_a_store_being_deleted(): void
    {
        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');
        $job = $this->service()->request(auth('web')->user(), 'A', 'a', 'Toko A');

        $this->expectExceptionMessage('Toko sedang atau sudah dihapus');
        $this->service()->guardAuthorization('A', 'Shopee', 'EXT-A', $job['requested_at']);
    }

    public function test_registered_files_are_removed_but_unsafe_paths_fail_closed(): void
    {
        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');
        $directory = storage_path('private/marketplace');
        File::ensureDirectoryExists($directory);
        $file = $directory.'/deletion-test-'.bin2hex(random_bytes(4)).'.pdf';
        file_put_contents($file, 'pdf');
        $outside = base_path('composer.json');
        DB::table('marketplace_store_files')->insert(['store_id' => 'a', 'relative_path' => 'private/marketplace/'.basename($file)]);

        $this->service()->request(auth('web')->user(), 'A', 'a', 'Toko A');
        $this->assertSame(1, $this->service()->preview('A', 'a')['file_count']);
        Artisan::call('marketplace:store-deletion', ['--store' => 'a']);

        $this->assertFileDoesNotExist($file);
        $this->assertSame('completed', $this->service()->status('A', 'a')['status']);

        DB::table('marketplace_store_files')->insert(['store_id' => 'a2', 'relative_path' => 'private/marketplace/../../composer.json']);
        $this->service()->request(auth('web')->user(), 'A', 'a2', 'Toko A2');
        Artisan::call('marketplace:store-deletion', ['--store' => 'a2']);

        $this->assertFileExists($outside);
        $this->assertSame('failed', $this->service()->status('A', 'a2')['status']);
        $this->assertNotNull(DB::table('marketplace_stores')->where('id', 'a2')->first());
    }
}
