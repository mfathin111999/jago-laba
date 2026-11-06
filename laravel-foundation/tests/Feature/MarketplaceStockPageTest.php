<?php

namespace Tests\Feature;

use App\Auth\AuthenticatedUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MarketplaceStockPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'CREATE TABLE workspace_user_access (user_key TEXT,workspace TEXT)',
            'CREATE TABLE marketplace_stores (id TEXT PRIMARY KEY, company_key TEXT, platform TEXT, store_name TEXT, store_identifier TEXT, status TEXT, authorization_status TEXT, sync_mode TEXT, default_account_id INTEGER, default_admin_fee_pct NUMERIC, default_free_shipping_pct NUMERIC, default_payment_fee_pct NUMERIC, total_transactions INTEGER DEFAULT 0, last_synced_at TEXT, archived_at TEXT, archived_by TEXT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE marketplace_oauth_connections (id INTEGER PRIMARY KEY, company_key TEXT, store_id TEXT, platform TEXT, status TEXT, authorized_at TEXT, token_expires_at TEXT, last_successful_sync_at TEXT, updated_at TEXT)',
            'CREATE TABLE marketplace_catalog_variants (id INTEGER PRIMARY KEY,store_id TEXT,external_product_id TEXT,external_variant_id TEXT,product_name TEXT,parent_sku TEXT,sku TEXT,variant_name TEXT,image_url TEXT,price NUMERIC,original_price NUMERIC,stock_qty NUMERIC,source_status TEXT,catalog_visible INTEGER)',
            'CREATE TABLE marketplace_sync_records (id INTEGER PRIMARY KEY,store_id TEXT,resource_type TEXT,external_id TEXT,payload_json TEXT,fetched_at TEXT)',
            'CREATE TABLE marketplace_orders (id TEXT PRIMARY KEY,store_id TEXT,source_kind TEXT,internal_state TEXT,order_date TEXT)',
            'CREATE TABLE marketplace_stock_minimums (id INTEGER PRIMARY KEY,store_id TEXT,product_id TEXT,variant_id TEXT,warehouse_id TEXT,minimum_qty NUMERIC,changed_by TEXT,changed_at TEXT)',
            'CREATE TABLE marketplace_stock_minimum_audit (id INTEGER PRIMARY KEY,company_key TEXT,store_id TEXT,product_id TEXT,variant_id TEXT,warehouse_id TEXT,previous_qty NUMERIC,new_qty NUMERIC,actor TEXT,created_at TEXT)',
            'CREATE TABLE marketplace_stock_snapshots (id INTEGER PRIMARY KEY,store_id TEXT,product_id TEXT,variant_id TEXT,warehouse_id TEXT,quantity NUMERIC,observed_at TEXT)',
        ] as $sql) {
            DB::statement($sql);
        }

        DB::table('marketplace_stores')->insert([
            ['id' => 'a', 'company_key' => 'A', 'platform' => 'TikTok Shop', 'store_name' => 'Toko A'],
            ['id' => 'b', 'company_key' => 'B', 'platform' => 'TikTok Shop', 'store_name' => 'Toko B'],
        ]);
        $variants = [
            ['id' => 1, 'store_id' => 'a', 'external_product_id' => 'P1', 'external_variant_id' => 'V1', 'product_name' => 'Tas Kulit', 'sku' => 'TAS-H', 'variant_name' => 'Hitam', 'stock_qty' => 3, 'source_status' => 'NORMAL', 'catalog_visible' => 1],
            ['id' => 2, 'store_id' => 'a', 'external_product_id' => 'P1', 'external_variant_id' => 'V2', 'product_name' => 'Tas Kulit', 'sku' => 'TAS-C', 'variant_name' => 'Cokelat', 'stock_qty' => 0, 'source_status' => 'NORMAL', 'catalog_visible' => 1],
            ['id' => 3, 'store_id' => 'a', 'external_product_id' => 'P2', 'external_variant_id' => 'V3', 'product_name' => 'Dompet', 'sku' => 'DMP', 'variant_name' => 'Standar', 'stock_qty' => null, 'source_status' => 'NORMAL', 'catalog_visible' => 1],
            ['id' => 4, 'store_id' => 'b', 'external_product_id' => 'P9', 'external_variant_id' => 'V9', 'product_name' => 'Rahasia B', 'sku' => 'RHS', 'variant_name' => 'x', 'stock_qty' => 5, 'source_status' => 'NORMAL', 'catalog_visible' => 1],
        ];
        foreach ($variants as $variant) {
            DB::table('marketplace_catalog_variants')->insert($variant);
        }
        DB::table('marketplace_sync_records')->insert(['store_id' => 'a', 'resource_type' => 'product', 'external_id' => 'P1', 'payload_json' => '{"secret":"PAYLOAD-RAHASIA","skus":[]}', 'fetched_at' => '2026-11-01 08:00:00']);
        DB::table('marketplace_stock_minimums')->insert(['store_id' => 'a', 'product_id' => 'P1', 'variant_id' => 'V1', 'warehouse_id' => '', 'minimum_qty' => 5]);
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

        return $this->withHeaders($headers)->get('/marketplace/stock'.$query);
    }

    public function test_the_page_lists_the_stores_variants_with_status_and_hides_the_provider_payload(): void
    {
        $response = $this->page()->assertOk()
            ->assertJsonPath('component', 'Marketplace/Stock')
            ->assertJsonPath('props.scope.store_id', 'a')
            ->assertJsonPath('props.can_edit_minimum', true)
            ->assertJsonCount(3, 'props.stock.rows')
            ->assertJsonPath('props.stock.summary.sku', 3);

        $status = collect($response->json('props.stock.rows'))->pluck('stock_status', 'sku')->all();
        $this->assertSame(['DMP' => 'unknown', 'TAS-C' => 'out', 'TAS-H' => 'low'], $status);

        foreach (['PAYLOAD-RAHASIA', 'payload_json', 'Rahasia B'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $response->getContent());
        }
    }

    public function test_a_stock_value_unknown_to_the_source_is_never_turned_into_a_number(): void
    {
        $dompet = collect($this->page()->json('props.stock.rows'))->firstWhere('sku', 'DMP');

        $this->assertNull($dompet['current']);
        $this->assertSame('unknown', $dompet['stock_status']);
    }

    public function test_another_companys_store_is_refused(): void
    {
        $this->page('?store_id=b')->assertForbidden();
    }

    public function test_the_minimum_can_be_set_from_the_page_and_a_reader_cannot(): void
    {
        $payload = ['store_id' => 'a', 'product_id' => 'P1', 'variant_id' => 'V2', 'warehouse_id' => '', 'minimum' => '4'];

        $this->withHeader('X-CSRF-TOKEN', 'tok')->postJson('/api/marketplace-stock.php', $payload)->assertOk()->assertJsonPath('success', true);
        $this->assertEquals(4, DB::table('marketplace_stock_minimums')->where('variant_id', 'V2')->value('minimum_qty'));
        $this->assertSame(1, DB::table('marketplace_stock_minimum_audit')->count());

        $this->withHeader('X-CSRF-TOKEN', 'tok')->postJson('/api/marketplace-stock.php', ['minimum' => '-1'] + $payload)->assertStatus(400);

        $this->loginAs('ROLE-PEMBACA');
        $this->page('?store_id=a')->assertOk()->assertJsonPath('props.can_edit_minimum', false);
        $this->withHeader('X-CSRF-TOKEN', 'tok')->postJson('/api/marketplace-stock.php', ['minimum' => '9'] + $payload)->assertForbidden();
        $this->assertEquals(4, DB::table('marketplace_stock_minimums')->where('variant_id', 'V2')->value('minimum_qty'));
    }
}
