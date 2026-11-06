<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MarketplaceCatalogReadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'CREATE TABLE workspace_user_access (user_key TEXT,workspace TEXT)',
            'CREATE TABLE marketplace_stores (id TEXT PRIMARY KEY, company_key TEXT, platform TEXT, store_name TEXT, archived_at TEXT)',
            'CREATE TABLE marketplace_catalog_variants (id INTEGER PRIMARY KEY,store_id TEXT,external_product_id TEXT,external_variant_id TEXT,product_name TEXT,parent_sku TEXT,sku TEXT,variant_name TEXT,price NUMERIC,original_price NUMERIC,stock_qty NUMERIC,source_status TEXT,catalog_visible INTEGER)',
            'CREATE TABLE marketplace_orders (id TEXT PRIMARY KEY,store_id TEXT,order_date TEXT,internal_state TEXT,order_status TEXT,total_cogs_hpp NUMERIC,net_settlement_amount NUMERIC,net_margin_profit NUMERIC,transaction_detail_json TEXT)',
            'CREATE TABLE marketplace_order_items (id INTEGER PRIMARY KEY,order_id TEXT,sku TEXT,item_name TEXT,qty INTEGER,selling_price NUMERIC,unit_cogs_hpp NUMERIC,subtotal_cogs NUMERIC)',
            'CREATE TABLE marketplace_sku_mappings (store_id TEXT,marketplace_sku TEXT,marketplace_product_name TEXT,product_variant_id TEXT,created_at TEXT)',
            'CREATE TABLE marketplace_hpp_history (id INTEGER PRIMARY KEY,store_id TEXT,marketplace_sku TEXT,product_variant_id TEXT,effective_from TEXT,unit_hpp NUMERIC,source TEXT,reason TEXT,changed_by TEXT,created_at TEXT)',
            'CREATE TABLE marketplace_hpp_changes (id INTEGER PRIMARY KEY,store_id TEXT,sku TEXT,old_hpp NUMERIC,new_hpp NUMERIC,changed_by TEXT,affected_orders INTEGER,created_at TEXT)',
            'CREATE TABLE marketplace_product_ads (id INTEGER PRIMARY KEY,store_id TEXT,sku TEXT,unit_cogs_hpp NUMERIC)',
            'CREATE TABLE system_audit_logs (id INTEGER PRIMARY KEY,event_id TEXT,event_time TEXT,user_id TEXT,username TEXT,module TEXT,action TEXT,record_id TEXT,reference_id TEXT,before_json TEXT,after_json TEXT,status TEXT,notes TEXT)',
        ] as $sql) {
            DB::statement($sql);
        }
        DB::table('workspace_user_access')->insert(['user_key' => 'reader', 'workspace' => 'marketplace']);
        DB::table('marketplace_stores')->insert([
            ['id' => 'a', 'company_key' => 'A', 'platform' => 'Shopee', 'store_name' => 'Toko A', 'archived_at' => null],
            ['id' => 'b', 'company_key' => 'B', 'platform' => 'Shopee', 'store_name' => 'Toko B', 'archived_at' => null],
        ]);
        DB::table('marketplace_catalog_variants')->insert([
            ['id' => 1, 'store_id' => 'a', 'external_product_id' => 'P1', 'external_variant_id' => 'V1', 'product_name' => '=SUM(1+1)', 'parent_sku' => 'P1', 'sku' => 'SKU-1', 'variant_name' => 'Hitam', 'price' => 100000, 'original_price' => null, 'stock_qty' => 0, 'source_status' => 'NORMAL', 'catalog_visible' => 1],
            ['id' => 2, 'store_id' => 'a', 'external_product_id' => 'P2', 'external_variant_id' => 'V2', 'product_name' => 'Unknown', 'parent_sku' => 'P2', 'sku' => 'SKU-2', 'variant_name' => 'Biru', 'price' => null, 'original_price' => null, 'stock_qty' => null, 'source_status' => 'NORMAL', 'catalog_visible' => 1],
        ]);
        DB::table('marketplace_hpp_history')->insert(['id' => 1, 'store_id' => 'a', 'marketplace_sku' => 'SKU-1', 'effective_from' => '2026-01-01', 'unit_hpp' => 45000]);
        DB::table('marketplace_orders')->insert(['id' => 'O1', 'store_id' => 'a', 'order_date' => '2026-10-31 23:59:59', 'internal_state' => null, 'order_status' => 'Selesai']);
        DB::table('marketplace_order_items')->insert(['id' => 1, 'order_id' => 'O1', 'sku' => 'SKU-1', 'item_name' => 'Tas', 'qty' => 2, 'selling_price' => 100000, 'unit_cogs_hpp' => 45000]);
        $this->actingAs(new LegacyUser(['id' => 'reader', 'role_id' => 'ROLE-PEMBACA', 'company_key' => 'A']), 'web');
        session(['workspace' => 'marketplace']);
    }

    public function test_catalog_http_matches_the_captured_legacy_listing_with_null_and_zero_preserved(): void
    {
        $before = DB::table('marketplace_catalog_variants')->get()->toJson();
        $filters = ['date_start' => '2026-10-01', 'date_end' => '2026-10-31'];
        $fixture = __DIR__.'/fixtures/legacy/catalog-listing.json';
        $legacy = json_decode((string) file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);
        $response = $this->getJson('/marketplace/catalog?'.http_build_query($filters + ['store_id' => 'a']));
        $response->assertOk()->assertExactJson(['catalog' => $legacy, 'filters' => $filters + ['store_id' => 'a'], 'read_only' => true]);
        $response->assertJsonPath('catalog.products.0.variants.0.stock_qty', 0)
            ->assertJsonPath('catalog.products.1.variants.0.hpp', null)
            ->assertJsonPath('catalog.products.1.variants.0.stock_qty', null)
            ->assertJsonPath('catalog.sold', 2);
        $this->assertSame($before, DB::table('marketplace_catalog_variants')->get()->toJson());
    }

    public function test_the_page_falls_back_to_the_first_store_and_flags_hpp_editing_by_role(): void
    {
        $headers = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml'];
        if (is_file(public_path('build/manifest.json'))) {
            $headers['X-Inertia-Version'] = hash_file('xxh128', public_path('build/manifest.json'));
        }

        $this->withHeaders($headers)->get('/marketplace/catalog')->assertOk()
            ->assertJsonPath('component', 'Marketplace/Catalog')
            ->assertJsonPath('props.scope.store_id', 'a')
            ->assertJsonPath('props.can_edit_hpp', false)
            ->assertJsonCount(1, 'props.stores');

        $this->actingAs(new LegacyUser(['id' => 'owner', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN', 'company_key' => 'A']), 'web');
        $this->withHeaders($headers)->get('/marketplace/catalog')->assertJsonPath('props.can_edit_hpp', true);
    }

    public function test_catalog_rejects_foreign_store_and_inactive_workspace(): void
    {
        $this->getJson('/marketplace/catalog?store_id=b')->assertForbidden();
        $this->withSession(['workspace' => 'konveksi'])->getJson('/marketplace/catalog?store_id=a')->assertForbidden();
    }

    public function test_catalog_csv_preserves_unknowns_and_escapes_formula_text(): void
    {
        $response = $this->get('/marketplace/catalog?store_id=a&format=csv')->assertOk()->assertDownload('katalog-marketplace.csv');
        $content = $response->streamedContent();
        $this->assertStringContainsString("'=SUM(1+1)", $content);
        $this->assertStringContainsString('SKU-2,Biru,,,,0', $content);
    }

    public function test_hpp_write_updates_sku_global_and_batch_via_legacy_and_pilot_routes(): void
    {
        // Switch to owner user with hpp.edit permissions
        $this->actingAs(new LegacyUser(['id' => 'owner', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN', 'company_key' => 'A']), 'web');
        session(['_token' => 'test-csrf-token', 'workspace' => 'marketplace']);
        $csrfToken = 'test-csrf-token';

        // 1. Update SKU HPP via legacy route
        $this->withHeader('X-CSRF-TOKEN', $csrfToken)
            ->postJson('/api/marketplace-ajax.php?action=update_sku_hpp', [
                'store_id' => 'a',
                'sku' => 'SKU-1',
                'hpp' => '50000',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(50000.0, $this->hppOn(now('Asia/Jakarta')->format('Y-m-d')));

        // 2. Update Global Product HPP via legacy route
        $this->withHeader('X-CSRF-TOKEN', $csrfToken)
            ->postJson('/api/marketplace-ajax.php?action=update_global_product_hpp', [
                'store_id' => 'a',
                'sku' => 'SKU-1',
                'hpp' => '55000',
                'apply_all_variants' => '0',
                'recalculate_history' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(55000.0, $this->hppOn(now('Asia/Jakarta')->format('Y-m-d')));

        // 3. Save HPP Batch via legacy route
        $this->withHeader('X-CSRF-TOKEN', $csrfToken)
            ->postJson('/api/marketplace-ajax.php?action=save_hpp_batch', [
                'changes' => json_encode([
                    ['store_id' => 'a', 'sku' => 'SKU-1', 'hpp' => '60000', 'effective_from' => '2026-11-01'],
                ]),
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sku_count', 1);

        $this->assertSame(60000.0, $this->hppOn('2026-11-01'));

        // 4. Update SKU HPP via pilot REST route
        $this->withHeader('X-CSRF-TOKEN', $csrfToken)
            ->postJson('/pilot/marketplace/hpp/sku', [
                'store_id' => 'a',
                'sku' => 'SKU-1',
                'hpp' => '65000',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(65000.0, $this->hppOn(now('Asia/Jakarta')->format('Y-m-d')));

        // 5. Update Global HPP via pilot REST route
        $this->withHeader('X-CSRF-TOKEN', $csrfToken)
            ->postJson('/pilot/marketplace/hpp/global', [
                'store_id' => 'a',
                'sku' => 'SKU-1',
                'hpp' => '70000',
                'apply_all_variants' => false,
                'recalculate_history' => false,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(70000.0, $this->hppOn(now('Asia/Jakarta')->format('Y-m-d')));

        // 6. Save Batch via pilot REST route
        $this->withHeader('X-CSRF-TOKEN', $csrfToken)
            ->postJson('/pilot/marketplace/hpp/batch', [
                'changes' => [
                    ['store_id' => 'a', 'sku' => 'SKU-1', 'hpp' => '75000', 'effective_from' => '2026-11-01'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sku_count', 1);

        $this->assertSame(75000.0, $this->hppOn('2026-11-01'));
    }

    public function test_hpp_refusals_keep_their_codes_statuses_and_request_id(): void
    {
        $this->actingAs(new LegacyUser(['id' => 'owner', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN', 'company_key' => 'A']), 'web');
        session(['_token' => 'test-csrf-token', 'workspace' => 'marketplace']);
        $post = fn (string $url, array $payload) => $this->withHeader('X-CSRF-TOKEN', 'test-csrf-token')->postJson($url, $payload);

        // An SKU that is not in the active store is refused as a domain error (403).
        $unknown = $post('/pilot/marketplace/hpp/global', ['store_id' => 'a', 'sku' => 'NO-SUCH-SKU', 'hpp' => '1000', 'apply_all_variants' => false, 'recalculate_history' => false])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'STORE_FORBIDDEN')
            ->assertJsonPath('message', 'SKU tidak ditemukan di toko aktif.');
        $this->assertSame($unknown->headers->get('X-Request-Id'), $unknown->json('request_id'));

        // Applying to all variants without a product id is an input error (422).
        $post('/pilot/marketplace/hpp/global', ['store_id' => 'a', 'hpp' => '1000', 'apply_all_variants' => true, 'recalculate_history' => false])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'HPP_INPUT_INVALID');

        // Batch: unknown SKU is HPP_FORBIDDEN (403); the same SKU twice is HPP_BATCH_INVALID (422).
        $post('/pilot/marketplace/hpp/batch', ['changes' => [['store_id' => 'a', 'sku' => 'NO-SUCH-SKU', 'hpp' => '1000']]])
            ->assertStatus(403)
            ->assertJsonPath('code', 'HPP_FORBIDDEN');
        $post('/pilot/marketplace/hpp/batch', ['changes' => [['store_id' => 'a', 'sku' => 'SKU-1', 'hpp' => '1000'], ['store_id' => 'a', 'sku' => 'SKU-1', 'hpp' => '2000']]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'HPP_BATCH_INVALID')
            ->assertJsonPath('message', 'SKU yang sama dikirim lebih dari sekali.');
    }

    private function hppOn(string $effectiveFrom): float
    {
        return (float) DB::table('marketplace_hpp_history')
            ->where('store_id', 'a')
            ->where('marketplace_sku', 'SKU-1')
            ->where('effective_from', $effectiveFrom)
            ->value('unit_hpp');
    }

    public function test_provider_inactive_actions_keep_legacy_contract_and_enforce_scope(): void
    {
        $this->actingAs(new LegacyUser(['id' => 'owner', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN', 'company_key' => 'A']), 'web');
        session(['_token' => 'test-csrf-token', 'workspace' => 'marketplace']);

        foreach (['test_api_connection', 'sync_marketing_ads'] as $action) {
            $this->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
                ->postJson('/api/marketplace-ajax.php?action='.$action, ['store_id' => 'a'])
                ->assertOk()
                ->assertJsonPath('success', false)
                ->assertJsonStructure(['success', 'message']);

            $this->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
                ->postJson('/api/marketplace-ajax.php?action='.$action, ['store_id' => 'b'])
                ->assertForbidden();
        }

        $this->actingAs(new LegacyUser(['id' => 'reader', 'role_id' => 'ROLE-PEMBACA', 'company_key' => 'A']), 'web');
        $this->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/api/marketplace-ajax.php?action=test_api_connection', ['store_id' => 'a'])
            ->assertForbidden();
    }
}
