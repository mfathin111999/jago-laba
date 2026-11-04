<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * In live mode the provider endpoints stop answering "connector inactive" and run the legacy services instead.
 * The legacy services need a real marketplace, so these tests check the wiring: the request reaches the legacy code
 * and its own answers (validation, not-found, not-connected) come back in the legacy shape. Sandbox stays 503.
 */
final class MarketplaceLiveEndpointsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('workspace_user_access', function (Blueprint $table): void {
            $table->string('user_key');
            $table->string('workspace');
        });
        Schema::create('marketplace_stores', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_key');
            $table->string('platform');
            $table->string('store_name')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
        Schema::create('marketplace_orders', function (Blueprint $table): void {
            $table->string('id');
            $table->string('store_id');
            $table->string('source_kind')->nullable();
            $table->dateTime('order_date')->nullable();
            $table->string('order_status')->nullable();
            $table->decimal('gross_sales', 18, 2)->nullable();
            $table->text('transaction_detail_json')->nullable();
            $table->string('internal_state')->nullable();
        });
        DB::table('marketplace_stores')->insert([['id' => 'a', 'company_key' => 'A', 'platform' => 'TikTok Shop', 'store_name' => 'Toko A', 'status' => 'Terhubung']]);
        DB::table('marketplace_orders')->insert([['id' => 'O1', 'store_id' => 'a', 'source_kind' => 'api', 'order_date' => '2026-11-01 10:00:00', 'order_status' => 'Pending', 'gross_sales' => 1000, 'transaction_detail_json' => json_encode(['source_status' => 'AWAITING_SHIPMENT']), 'internal_state' => 'active']]);
        DB::table('workspace_user_access')->insert([['user_key' => 'u1', 'workspace' => 'marketplace']]);
        $this->actingAs(new LegacyUser(['id' => 'u1', 'username' => 'u1', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN', 'company_key' => 'A']), 'web');
        session(['_token' => 'tok', 'workspace' => 'marketplace']);
    }

    /** @param array<string, mixed> $payload */
    private function send(string $url, array $payload): TestResponse
    {
        return $this->withHeader('X-CSRF-TOKEN', 'tok')->postJson($url, $payload);
    }

    public function test_sandbox_mode_still_answers_connector_inactive_everywhere(): void
    {
        config(['jago.oauth.mode' => 'sandbox']);

        $this->send('/api/marketplace-fulfillment.php', ['store_id' => 'a', 'order_id' => 'O1', 'action' => 'options'])->assertStatus(503)->assertJsonPath('error_code', 'PROVIDER_CONNECTOR_INACTIVE');
        $this->send('/api/marketplace-price.php', ['store_id' => 'a', 'product_id' => 'P1', 'action' => 'prepare'])->assertStatus(503);
    }

    public function test_live_fulfillment_validates_like_the_legacy_endpoint_and_reaches_the_legacy_service(): void
    {
        config(['jago.oauth.mode' => 'live']);

        $this->send('/api/marketplace-fulfillment.php', ['store_id' => 'a', 'order_id' => 'O1', 'action' => 'confirm'])
            ->assertStatus(403)->assertJsonPath('message', 'Konfirmasi pengaturan pengiriman diperlukan.');
        $this->send('/api/marketplace-fulfillment.php', ['store_id' => 'a', 'order_id' => 'bad id!', 'action' => 'options'])->assertStatus(422);

        $response = $this->send('/api/marketplace-fulfillment.php', ['store_id' => 'a', 'order_id' => 'O1', 'action' => 'options']);
        $this->assertNotSame(503, $response->status(), 'live mode must not answer connector-inactive');
        $response->assertJsonPath('success', false);
        $this->assertNotSame('', (string) $response->json('message'));
    }

    public function test_live_price_editor_reaches_the_legacy_service(): void
    {
        config(['jago.oauth.mode' => 'live']);

        $response = $this->send('/api/marketplace-price.php', ['store_id' => 'a', 'product_id' => 'P1', 'action' => 'prepare']);

        $this->assertNotSame(503, $response->status());
        $response->assertJsonPath('success', false);
        $this->assertNotSame('', (string) $response->json('message'));
    }

    public function test_live_order_finance_and_shipping_documents_reach_the_legacy_services(): void
    {
        config(['jago.oauth.mode' => 'live']);

        $finance = $this->send('/api/marketplace-order-finance.php', ['store_id' => 'a', 'order_id' => 'NOPE']);
        $this->assertNotSame(503, $finance->status());
        $finance->assertJsonPath('success', false);

        $document = $this->send('/api/marketplace-shipping-document.php', ['store_id' => 'a', 'order_id' => 'NOPE']);
        $this->assertNotSame(503, $document->status());
        $document->assertJsonPath('success', false);
    }
}
