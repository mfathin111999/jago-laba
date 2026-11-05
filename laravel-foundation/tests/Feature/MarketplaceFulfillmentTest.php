<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MarketplaceFulfillmentTest extends TestCase
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
            $table->timestamp('archived_at')->nullable();
        });
        Schema::create('marketplace_orders', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('store_id');
            $table->string('order_status');
            $table->timestamp('updated_at')->nullable();
        });
        Schema::create('marketplace_fulfillment_actions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('company_key');
            $table->string('store_id');
            $table->string('package_id');
            $table->string('order_id')->nullable();
            $table->string('user_key')->nullable();
            $table->string('state')->nullable();
            $table->string('offer_hash')->nullable();
            $table->text('offer_json')->nullable();
            $table->dateTime('offer_expires_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        DB::table('marketplace_stores')->insert([
            ['id' => 'tt', 'company_key' => 'A', 'platform' => 'TikTok Shop', 'store_name' => 'TikTok A', 'archived_at' => null],
            ['id' => 'foreign', 'company_key' => 'B', 'platform' => 'TikTok Shop', 'store_name' => 'B', 'archived_at' => null],
        ]);
        DB::table('marketplace_orders')->insert([
            ['id' => 'O1', 'store_id' => 'tt', 'order_status' => 'Pending'],
            ['id' => 'OF', 'store_id' => 'foreign', 'order_status' => 'Pending'],
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
    private function fulfil(array $payload): TestResponse
    {
        return $this->withHeader('X-CSRF-TOKEN', 'tok')->postJson('/api/marketplace-fulfillment.php', $payload);
    }

    public function test_options_never_invent_courier_data_while_the_provider_is_disabled(): void
    {
        $response = $this->fulfil(['action' => 'options', 'store_id' => 'tt', 'order_id' => 'O1'])
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'PROVIDER_CONNECTOR_INACTIVE');

        $this->assertStringNotContainsString('J&T', $response->getContent());
        $this->assertStringNotContainsString('offer_token', $response->getContent());
        $this->assertSame(0, DB::table('marketplace_fulfillment_actions')->count(), 'no fake offer may be stored');
    }

    public function test_confirm_never_marks_an_order_as_shipped_without_a_courier(): void
    {
        $this->fulfil(['action' => 'confirm', 'confirmed' => '1', 'store_id' => 'tt', 'order_id' => 'O1', 'package_id' => 'P1', 'offer_token' => 'anything', 'method' => 'PICKUP'])
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'PROVIDER_CONNECTOR_INACTIVE');

        $this->assertSame('Pending', DB::table('marketplace_orders')->where('id', 'O1')->value('order_status'));
        $this->assertDatabaseMissing('system_audit_logs', ['action' => 'CONFIRM_FULFILLMENT']);
        $this->assertSame(0, DB::table('marketplace_fulfillment_actions')->count());
    }

    public function test_scope_input_and_authorization_are_still_enforced(): void
    {
        $this->fulfil(['action' => 'options', 'store_id' => 'tt', 'order_id' => 'NOPE'])->assertForbidden()
            ->assertJsonPath('message', 'Pesanan tidak ditemukan di toko aktif.');
        $this->fulfil(['action' => 'options', 'store_id' => 'foreign', 'order_id' => 'OF'])->assertForbidden();
        $this->fulfil(['action' => 'confirm', 'confirmed' => '1', 'store_id' => 'tt', 'order_id' => 'OF'])->assertForbidden();
        $this->fulfil(['action' => 'options', 'store_id' => 'tt'])->assertStatus(422);
        $this->fulfil(['action' => 'bogus', 'store_id' => 'tt', 'order_id' => 'O1'])->assertStatus(422);
        $this->fulfil(['action' => 'confirm', 'store_id' => 'tt', 'order_id' => 'O1'])->assertForbidden()
            ->assertJsonPath('message', 'Konfirmasi pengaturan pengiriman diperlukan.');
        $this->withHeader('X-CSRF-TOKEN', 'wrong')->postJson('/api/marketplace-fulfillment.php', ['action' => 'options', 'store_id' => 'tt', 'order_id' => 'O1'])->assertStatus(419);

        $this->loginAs('ROLE-PEMBACA');
        $this->fulfil(['action' => 'options', 'store_id' => 'tt', 'order_id' => 'O1'])->assertForbidden();
        $this->assertSame('Pending', DB::table('marketplace_orders')->where('id', 'O1')->value('order_status'));
    }
}
