<?php

namespace Tests\Feature;

use App\Auth\AuthenticatedUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MarketplaceSyncPageTest extends TestCase
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
            $table->string('external_store_name')->nullable();
            $table->text('access_token_encrypted')->nullable();
            $table->dateTime('authorized_at')->nullable();
            $table->dateTime('token_expires_at')->nullable();
            $table->dateTime('last_successful_sync_at')->nullable();
            $table->dateTime('updated_at')->nullable();
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

        DB::table('marketplace_stores')->insert([
            ['id' => 'a', 'company_key' => 'A', 'platform' => 'TikTok Shop', 'store_name' => 'Toko A'],
            ['id' => 'u', 'company_key' => 'A', 'platform' => 'Shopee', 'store_name' => 'Toko Belum Terhubung'],
            ['id' => 'b', 'company_key' => 'B', 'platform' => 'Shopee', 'store_name' => 'Toko B'],
        ]);
        DB::table('marketplace_oauth_connections')->insert([
            ['company_key' => 'A', 'store_id' => 'a', 'platform' => 'TikTok Shop', 'status' => 'verified', 'external_store_name' => 'Ext A', 'access_token_encrypted' => 'SECRET-ENC', 'last_successful_sync_at' => '2026-11-01 08:00:00'],
        ]);
        DB::table('marketplace_orders')->insert([['id' => 'O1', 'store_id' => 'a'], ['id' => 'O2', 'store_id' => 'a']]);
        DB::table('marketplace_auto_sync')->insert([['store_id' => 'a', 'company_key' => 'A', 'mode' => 'status', 'enabled' => 1, 'last_error_code' => 'PROVIDER_TIMEOUT']]);
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

        return $this->withHeaders($headers)->get('/marketplace/sync'.$query);
    }

    public function test_the_page_shows_the_store_coverage_queue_and_schedules_without_credentials(): void
    {
        $response = $this->page()->assertOk()
            ->assertJsonPath('component', 'Marketplace/Sync')
            ->assertJsonPath('props.scope.store_id', 'a')
            ->assertJsonPath('props.preview.order_count', 2)
            ->assertJsonPath('props.preview.platform', 'TikTok Shop')
            ->assertJsonPath('props.status.pending', 0)
            ->assertJsonPath('props.can_sync', true)
            ->assertJsonPath('props.can_manage', true)
            ->assertJsonCount(5, 'props.auto');

        $status = collect($response->json('props.auto'))->firstWhere('mode', 'status');
        $this->assertTrue($status['enabled']);
        $this->assertSame('PROVIDER_TIMEOUT', $status['last_error_code']);
        $this->assertStringNotContainsString('SECRET-ENC', $response->getContent());
    }

    public function test_a_store_without_a_verified_connection_has_no_sync_preview(): void
    {
        $this->page('?store_id=u')->assertOk()->assertJsonPath('props.preview', null);
    }

    public function test_another_companys_store_is_refused(): void
    {
        $this->page('?store_id=b')->assertForbidden();
    }

    public function test_the_actions_used_by_the_page_queue_one_run_and_flip_a_schedule(): void
    {
        $headers = ['X-CSRF-TOKEN' => 'tok'];

        $this->withHeaders($headers)->postJson('/api/marketplace-sync.php', ['action' => 'start', 'store_id' => 'a'])
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.pending', 1);
        $this->withHeaders($headers)->postJson('/api/marketplace-sync.php', ['action' => 'start', 'store_id' => 'a'])->assertOk();
        $this->assertSame(1, DB::table('marketplace_sync_jobs')->where('store_id', 'a')->count(), 'a pending run is reused, not duplicated');

        $this->withHeaders($headers)->postJson('/api/marketplace-auto-sync.php', ['store_id' => 'a', 'mode' => 'new', 'enabled' => '1'])
            ->assertOk()->assertJsonPath('states.new.enabled', true);
        $this->page()->assertJsonPath('props.status.pending', 1);
    }

    public function test_a_reader_sees_the_page_but_cannot_start_or_change_schedules(): void
    {
        $this->loginAs('ROLE-PEMBACA');
        $this->page('?store_id=a')->assertOk()->assertJsonPath('props.can_sync', false)->assertJsonPath('props.can_manage', false);

        $headers = ['X-CSRF-TOKEN' => 'tok'];
        $this->withHeaders($headers)->postJson('/api/marketplace-auto-sync.php', ['store_id' => 'a', 'mode' => 'new', 'enabled' => '1'])->assertForbidden();
        $this->assertSame(0, DB::table('marketplace_auto_sync')->where('mode', 'new')->count());
    }
}
