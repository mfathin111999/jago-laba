<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MarketplaceOAuthAuthorizationTest extends TestCase
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
            $table->string('status')->nullable();
            $table->string('authorization_status')->nullable();
            $table->timestamp('archived_at')->nullable();
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
            $table->dateTime('token_expires_at')->nullable();
            $table->dateTime('refresh_token_expires_at')->nullable();
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

        DB::table('marketplace_stores')->insert([
            ['id' => 'a', 'company_key' => 'A', 'platform' => 'Shopee', 'store_name' => 'Toko A', 'status' => 'Terhubung', 'authorization_status' => 'verified'],
            ['id' => 'unverified', 'company_key' => 'A', 'platform' => 'Shopee', 'store_name' => 'Belum', 'status' => 'Terputus', 'authorization_status' => 'pending'],
            ['id' => 'b', 'company_key' => 'B', 'platform' => 'Shopee', 'store_name' => 'Toko B', 'status' => 'Terhubung', 'authorization_status' => 'verified'],
        ]);
        DB::table('marketplace_oauth_connections')->insert([
            ['company_key' => 'A', 'store_id' => 'a', 'platform' => 'Shopee', 'status' => 'verified', 'access_token_encrypted' => 'enc', 'refresh_token_encrypted' => 'enc'],
            ['company_key' => 'B', 'store_id' => 'b', 'platform' => 'Shopee', 'status' => 'verified', 'access_token_encrypted' => 'enc', 'refresh_token_encrypted' => 'enc'],
        ]);
        DB::table('workspace_user_access')->insert([['user_key' => 'u1', 'workspace' => 'marketplace']]);
        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');
    }

    private function loginAs(string $role): void
    {
        $this->actingAs(new LegacyUser(['id' => 'u1', 'username' => 'u1', 'role_id' => $role, 'company_key' => 'A']), 'web');
        session(['_token' => 'tok', 'workspace' => 'marketplace']);
    }

    /** @param array<string, mixed> $payload */
    private function oauth(string $action, array $payload = []): TestResponse
    {
        return $this->withHeader('X-CSRF-TOKEN', 'tok')->postJson('/api/marketplace-oauth.php?action='.$action, $payload);
    }

    public function test_only_administrators_can_connect_refresh_or_disconnect_stores(): void
    {
        foreach (['ROLE-PEMBACA', 'ROLE-KEUANGAN', 'ROLE-OPERASIONAL'] as $role) {
            $this->loginAs($role);
            foreach (['start', 'pending', 'select', 'refresh', 'disconnect'] as $action) {
                $this->oauth($action, ['store_id' => 'a', 'platform' => 'Shopee', 'pending_id' => 'x', 'external_store_id' => 'y'])
                    ->assertForbidden()->assertJsonPath('code', 'ROLE_FORBIDDEN');
            }
        }

        $this->assertSame('verified', DB::table('marketplace_oauth_connections')->where('store_id', 'a')->value('status'));
        $this->assertSame('enc', DB::table('marketplace_oauth_connections')->where('store_id', 'a')->value('access_token_encrypted'));
    }

    public function test_status_stays_readable_for_every_marketplace_user(): void
    {
        $this->loginAs('ROLE-PEMBACA');

        $this->getJson('/api/marketplace-oauth.php?action=status')->assertOk();
    }

    public function test_sync_really_queues_a_run_for_a_verified_store_and_does_not_duplicate_it(): void
    {
        $this->loginAs('ROLE-KEUANGAN');

        $first = $this->oauth('sync', ['store_id' => 'a'])->assertOk()->assertJsonPath('success', true);
        $this->assertSame(1, DB::table('marketplace_sync_jobs')->count(), 'a sync request must create a queued run');
        $this->assertSame('queued', DB::table('marketplace_sync_jobs')->value('status'));
        $this->assertNotEmpty($first->json('data.job_id'));

        $second = $this->oauth('sync', ['store_id' => 'a'])->assertOk();
        $this->assertSame(1, DB::table('marketplace_sync_jobs')->count(), 'a pending run is reused');
        $this->assertSame($first->json('data.job_id'), $second->json('data.job_id'));
    }

    public function test_sync_is_refused_for_other_companies_unverified_stores_and_readers(): void
    {
        $this->oauth('sync', ['store_id' => 'b'])->assertForbidden();
        $this->oauth('sync', ['store_id' => ''])->assertForbidden();
        $this->oauth('sync', ['store_id' => 'unverified'])->assertStatus(400);
        $this->assertSame(0, DB::table('marketplace_sync_jobs')->count());

        $this->loginAs('ROLE-PEMBACA');
        $this->oauth('sync', ['store_id' => 'a'])->assertForbidden()->assertJsonPath('code', 'ROLE_FORBIDDEN');
        $this->assertSame(0, DB::table('marketplace_sync_jobs')->count());
    }
}
