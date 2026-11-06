<?php

namespace Tests\Feature;

use App\Auth\AuthenticatedUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MarketplaceStoresPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createWorkspaceAccessTable();
        $this->createAuditLogTable();
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
            $table->string('api_access_token')->nullable();
            $table->string('api_app_key')->nullable();
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

        foreach ([
            ['id' => 'a', 'company_key' => 'A', 'platform' => 'Shopee', 'store_name' => 'Toko A', 'store_identifier' => 'ext-a', 'status' => 'Terhubung', 'authorization_status' => 'verified',
                'default_admin_fee_pct' => 11, 'default_free_shipping_pct' => 4, 'default_payment_fee_pct' => 1, 'api_access_token' => 'SECRET-TOKEN-A', 'api_app_key' => 'SECRET-KEY-A'],
            ['id' => 'old', 'company_key' => 'A', 'platform' => 'Lazada', 'store_name' => 'Toko Lama', 'status' => 'Terputus', 'archived_at' => '2026-10-01 00:00:00', 'archived_by' => 'admin'],
            ['id' => 'b', 'company_key' => 'B', 'platform' => 'Shopee', 'store_name' => 'Toko B Rahasia', 'status' => 'Terhubung'],
        ] as $store) {
            DB::table('marketplace_stores')->insert($store);
        }
        DB::table('marketplace_oauth_connections')->insert([
            ['company_key' => 'A', 'store_id' => 'a', 'platform' => 'Shopee', 'status' => 'verified', 'access_token_encrypted' => 'ENC-SECRET', 'refresh_token_encrypted' => 'ENC-SECRET-2', 'last_successful_sync_at' => '2026-11-01 08:00:00'],
        ]);
        DB::table('workspace_user_access')->insert([['user_key' => 'u1', 'workspace' => 'marketplace']]);
        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');
    }

    private function loginAs(string $role): void
    {
        $this->actingAs(new AuthenticatedUser(['id' => 'u1', 'username' => 'u1', 'role_id' => $role, 'company_key' => 'A']), 'web');
        session(['_token' => 'tok', 'workspace' => 'marketplace']);
    }

    private function page(): TestResponse
    {
        $headers = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml'];
        if (is_file(public_path('build/manifest.json'))) {
            $headers['X-Inertia-Version'] = hash_file('xxh128', public_path('build/manifest.json'));
        }

        return $this->withHeaders($headers)->get('/marketplace/stores');
    }

    /** @param array<string, mixed> $payload */
    private function send(string $method, string $url, array $payload = []): TestResponse
    {
        return $this->withHeader('X-CSRF-TOKEN', 'tok')->json($method, $url, $payload);
    }

    public function test_the_page_lists_only_this_companys_stores_and_never_exposes_credentials(): void
    {
        $response = $this->page()->assertOk();
        $response->assertJsonPath('component', 'Marketplace/Stores')
            ->assertJsonPath('props.scope.company_key', 'A')
            ->assertJsonCount(1, 'props.stores')
            ->assertJsonCount(1, 'props.archived')
            ->assertJsonPath('props.stores.0.id', 'a')
            ->assertJsonPath('props.stores.0.connection.status', 'verified')
            ->assertJsonPath('props.stores.0.connection.last_successful_sync_at', '2026-11-01 08:00:00')
            ->assertJsonPath('props.archived.0.id', 'old')
            ->assertJsonPath('props.archived.0.archived_by', 'admin');

        $body = $response->getContent();
        foreach (['SECRET-TOKEN-A', 'SECRET-KEY-A', 'ENC-SECRET', 'Rahasia', 'api_access_token'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    public function test_action_flags_follow_the_role(): void
    {
        $this->page()->assertJsonPath('props.can_manage', true)->assertJsonPath('props.can_delete', true)->assertJsonPath('props.deletion_ready', false);

        $this->loginAs('ROLE-ADMINISTRATOR');
        $this->page()->assertJsonPath('props.can_manage', true)->assertJsonPath('props.can_delete', false)->assertJsonPath('props.deletions', []);

        $this->loginAs('ROLE-PEMBACA');
        $this->page()->assertOk()->assertJsonPath('props.can_manage', false)->assertJsonPath('props.can_delete', false);
    }

    public function test_the_calls_made_by_the_page_work_end_to_end(): void
    {
        $this->send('POST', '/pilot/marketplace/stores', [
            'store_name' => 'Toko Baru', 'platform' => 'TikTok Shop', 'store_identifier' => '',
            'default_admin_fee_pct' => '9', 'default_free_shipping_pct' => '3', 'default_payment_fee_pct' => '1.5',
        ])->assertOk()->assertJsonPath('success', true);
        $new = DB::table('marketplace_stores')->where('store_name', 'Toko Baru')->first();
        $this->assertSame('A', $new->company_key);
        $this->assertSame('Terputus', $new->status, 'a store is never created as connected');
        $this->assertEquals(9, $new->default_admin_fee_pct);

        $this->send('PUT', '/pilot/marketplace/stores/'.$new->id, [
            'store_name' => 'Toko Baru 2', 'platform' => 'TikTok Shop', 'store_identifier' => 'x1',
            'default_admin_fee_pct' => '10', 'default_free_shipping_pct' => '3', 'default_payment_fee_pct' => '1.5',
        ])->assertOk();
        $this->assertSame('Toko Baru 2', DB::table('marketplace_stores')->where('id', $new->id)->value('store_name'));

        $this->send('PATCH', '/pilot/marketplace/stores/a/status', ['status' => 'Nonaktif'])->assertOk();
        $this->assertSame('Nonaktif', DB::table('marketplace_stores')->where('id', 'a')->value('status'));

        $this->send('POST', '/pilot/marketplace/stores/a/archive')->assertOk();
        $this->assertNotNull(DB::table('marketplace_stores')->where('id', 'a')->value('archived_at'));
        $this->page()->assertJsonCount(2, 'props.archived');

        $this->send('POST', '/pilot/marketplace/stores/a/restore')->assertOk();
        $this->assertNull(DB::table('marketplace_stores')->where('id', 'a')->value('archived_at'));
    }

    public function test_refusals_keep_their_codes_statuses_and_request_id(): void
    {
        // The request validator already refuses "Terhubung" with the standard validation shape (no custom code).
        $this->send('PATCH', '/pilot/marketplace/stores/a/status', ['status' => 'Terhubung'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Status terhubung hanya dapat ditetapkan setelah verifikasi API resmi.');

        // An archived store is refused by the store-context middleware before the service runs (403, not 404).
        $this->send('POST', '/pilot/marketplace/stores/old/archive')->assertForbidden()->assertJsonPath('code', 'STORE_FORBIDDEN');

        // Restoring has no store-context middleware: "a" is active, so there is no archived store to restore.
        $restored = $this->send('POST', '/pilot/marketplace/stores/a/restore')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'STORE_NOT_FOUND')
            ->assertJsonPath('message', 'Toko arsip tidak ditemukan.');
        $this->assertSame($restored->headers->get('X-Request-Id'), $restored->json('request_id'));
    }

    public function test_the_calls_refuse_other_companies_missing_names_and_non_administrators(): void
    {
        $this->send('POST', '/pilot/marketplace/stores', ['store_name' => '', 'platform' => 'Shopee'])->assertStatus(422)->assertJsonPath('success', false);
        $this->send('PUT', '/pilot/marketplace/stores/b', ['store_name' => 'Dibajak', 'platform' => 'Shopee'])->assertForbidden();
        $this->send('POST', '/pilot/marketplace/stores/b/archive')->assertForbidden();
        $this->assertSame('Toko B Rahasia', DB::table('marketplace_stores')->where('id', 'b')->value('store_name'));

        $this->loginAs('ROLE-PEMBACA');
        $this->send('POST', '/pilot/marketplace/stores', ['store_name' => 'Tidak boleh', 'platform' => 'Shopee'])->assertForbidden();
        $this->send('POST', '/pilot/marketplace/stores/a/archive')->assertForbidden();
        $this->assertSame(0, DB::table('marketplace_stores')->where('store_name', 'Tidak boleh')->count());
    }
}
