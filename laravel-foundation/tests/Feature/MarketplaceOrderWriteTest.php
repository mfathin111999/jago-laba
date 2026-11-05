<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MarketplaceOrderWriteTest extends TestCase
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
            $table->timestamp('order_date')->nullable();
            $table->timestamp('completion_date')->nullable();
            $table->string('buyer_username')->nullable();
            $table->string('order_status')->default('Selesai');
            $table->decimal('gross_sales', 16, 2)->default(0);
            $table->decimal('seller_discount', 16, 2)->default(0);
            $table->decimal('platform_admin_fee', 16, 2)->default(0);
            $table->decimal('service_fee', 16, 2)->default(0);
            $table->decimal('payment_fee', 16, 2)->default(0);
            $table->decimal('shipping_adjustment', 16, 2)->default(0);
            $table->decimal('other_deductions', 16, 2)->default(0);
            $table->decimal('operational_expense', 16, 2)->default(0);
            $table->decimal('adjustment_amount', 16, 2)->default(0);
            $table->decimal('net_settlement_amount', 16, 2)->default(0);
            $table->decimal('total_cogs_hpp', 16, 2)->default(0);
            $table->decimal('net_margin_profit', 16, 2)->default(0);
            $table->string('escrow_status')->nullable();
            $table->string('source_kind')->default('api');
            $table->string('internal_state')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('archived_by')->nullable();
            $table->string('archive_reason')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('marketplace_order_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('order_id');
            $table->string('product_variant_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('item_name')->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('selling_price', 16, 2)->default(0);
            $table->decimal('unit_cogs_hpp', 16, 2)->default(0);
            $table->decimal('subtotal_price', 16, 2)->default(0);
            $table->decimal('subtotal_cogs', 16, 2)->default(0);
        });
        Schema::create('marketplace_journal_entries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('journal_no');
            $table->string('entry_date');
            $table->string('ref_type')->nullable();
            $table->string('ref_id')->nullable();
            $table->string('store_id');
            $table->string('account_code')->nullable();
            $table->string('memo')->nullable();
            $table->decimal('debit', 16, 2)->default(0);
            $table->decimal('credit', 16, 2)->default(0);
        });

        DB::table('marketplace_stores')->insert([
            ['id' => 'a', 'company_key' => 'A', 'platform' => 'Shopee', 'store_name' => 'Toko A', 'archived_at' => null],
            ['id' => 'b', 'company_key' => 'B', 'platform' => 'Shopee', 'store_name' => 'Toko B', 'archived_at' => null],
        ]);
        DB::table('marketplace_journal_entries')->insert([
            ['journal_no' => 'J1', 'entry_date' => '2026-11-01', 'ref_type' => 'X', 'ref_id' => 'O1', 'store_id' => 'a', 'debit' => 100, 'credit' => 0],
            ['journal_no' => 'J1', 'entry_date' => '2026-11-01', 'ref_type' => 'X', 'ref_id' => 'O1', 'store_id' => 'a', 'debit' => 0, 'credit' => 100],
            ['journal_no' => 'J2', 'entry_date' => '2026-11-01', 'ref_type' => 'X', 'ref_id' => 'OB', 'store_id' => 'b', 'debit' => 777, 'credit' => 0],
        ]);
        DB::table('workspace_user_access')->insert([['user_key' => 'u1', 'workspace' => 'marketplace']]);
        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');
    }

    private function loginAs(string $role): void
    {
        $this->actingAs(new LegacyUser(['id' => 'u1', 'username' => 'kasir', 'role_id' => $role, 'company_key' => 'A']), 'web');
        session(['_token' => 'tok', 'workspace' => 'marketplace']);
    }

    /** @param array<string, mixed> $payload */
    private function ajax(string $action, array $payload): TestResponse
    {
        return $this->withHeader('X-CSRF-TOKEN', 'tok')->postJson('/api/marketplace-ajax.php?action='.$action, $payload);
    }

    public function test_the_journal_only_ever_shows_the_entries_of_a_store_the_user_may_access(): void
    {
        $own = $this->ajax('get_journal_entries', ['store_id' => 'a'])->assertOk();
        $own->assertJsonPath('data.count', 2)->assertJsonPath('data.is_balanced', true);
        $this->assertStringNotContainsString('777', $own->getContent());

        foreach (['ALL', '', 'b'] as $storeId) {
            $response = $this->ajax('get_journal_entries', ['store_id' => $storeId]);
            $response->assertForbidden();
            $this->assertStringNotContainsString('777', $response->getContent(), "store_id='{$storeId}' must not reveal other companies");
        }
    }

    public function test_a_manual_order_needs_only_the_essentials_and_computes_the_net_on_the_server(): void
    {
        $this->ajax('add_order', [
            'store_id' => 'a', 'order_id' => 'M-1', 'gross_sales' => 100000, 'platform_admin_fee' => 8000, 'service_fee' => 2000,
            'total_cogs_hpp' => 50000, 'net_settlement_amount' => 99999999, 'net_margin_profit' => 99999999,
        ])->assertOk()->assertJsonPath('order_id', 'M-1');

        $order = DB::table('marketplace_orders')->where('id', 'M-1')->first();
        $this->assertSame('manual', $order->source_kind);
        $this->assertSame('Selesai', $order->order_status);
        $this->assertSame('Customer Direct', $order->buyer_username);
        $this->assertEquals(90000, $order->net_settlement_amount, 'figures from the screen are ignored');
        $this->assertEquals(40000, $order->net_margin_profit);
        $this->assertSame(1, DB::table('marketplace_order_items')->where('order_id', 'M-1')->count());
        $this->assertDatabaseHas('system_audit_logs', ['action' => 'ADD_MANUAL_ORDER', 'record_id' => 'M-1']);
    }

    public function test_invalid_manual_orders_are_refused_with_a_clear_message_and_nothing_is_saved(): void
    {
        $this->ajax('add_order', ['store_id' => 'a', 'order_id' => 'M-1', 'gross_sales' => 1000])->assertOk();

        $cases = [
            [['order_id' => 'M-1', 'gross_sales' => 1000], 'sudah digunakan'],
            [['order_id' => '', 'gross_sales' => 1000], 'Nomor pesanan wajib diisi.'],
            [['order_id' => 'M-2', 'gross_sales' => -5], 'Nominal pesanan tidak valid.'],
            [['order_id' => 'M-2', 'gross_sales' => 1000, 'platform_admin_fee' => 'abc'], 'Nominal pesanan tidak valid.'],
            [['order_id' => 'M-2', 'gross_sales' => 1000, 'order_status' => 'Dihack'], 'Status pesanan manual tidak valid.'],
        ];
        foreach ($cases as [$override, $message]) {
            $this->ajax('add_order', ['store_id' => 'a'] + $override)->assertStatus(422)
                ->assertJsonPath('success', false);
            $this->assertStringContainsString($message, (string) $this->ajax('add_order', ['store_id' => 'a'] + $override)->json('message'));
        }

        $this->assertSame(1, DB::table('marketplace_orders')->count());
        $this->ajax('add_order', ['store_id' => 'b', 'order_id' => 'M-9', 'gross_sales' => 1])->assertForbidden();
    }

    public function test_manual_orders_can_be_updated_and_archived_but_api_orders_cannot_be_edited(): void
    {
        $this->ajax('add_order', ['store_id' => 'a', 'order_id' => 'M-1', 'gross_sales' => 100000, 'platform_admin_fee' => 10000])->assertOk();
        DB::table('marketplace_orders')->insert(['id' => 'API-1', 'store_id' => 'a', 'source_kind' => 'api', 'gross_sales' => 5]);

        $this->ajax('update_manual_order', ['store_id' => 'a', 'order_id' => 'M-1', 'order_status' => 'Retur', 'total_cogs_hpp' => 30000])->assertOk();
        $order = DB::table('marketplace_orders')->where('id', 'M-1')->first();
        $this->assertSame('Retur', $order->order_status);
        $this->assertEquals(60000, $order->net_margin_profit, '90000 net - 30000 HPP');

        $this->ajax('update_manual_order', ['store_id' => 'a', 'order_id' => 'M-1', 'order_status' => 'Aneh'])->assertStatus(422);
        $this->ajax('update_manual_order', ['store_id' => 'a', 'order_id' => 'API-1', 'order_status' => 'Selesai'])->assertStatus(422);

        $this->ajax('archive_order', ['store_id' => 'a', 'order_id' => 'M-1', 'reason' => ''])->assertStatus(422);
        $this->ajax('archive_order', ['store_id' => 'a', 'order_id' => 'M-1', 'reason' => 'Salah input'])->assertOk();
        $this->assertSame('archived', DB::table('marketplace_orders')->where('id', 'M-1')->value('internal_state'));
        $this->assertSame('kasir', DB::table('marketplace_orders')->where('id', 'M-1')->value('archived_by'));
        $this->ajax('update_manual_order', ['store_id' => 'a', 'order_id' => 'M-1', 'order_status' => 'Selesai'])->assertStatus(422);
        $this->ajax('archive_order', ['store_id' => 'a', 'order_id' => 'M-1', 'reason' => 'Lagi'])->assertOk()
            ->assertJsonPath('message', 'Pesanan sudah diarsipkan sebelumnya.');
    }
}
