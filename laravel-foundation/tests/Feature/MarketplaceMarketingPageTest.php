<?php

namespace Tests\Feature;

use App\Auth\AuthenticatedUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MarketplaceMarketingPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'CREATE TABLE workspace_user_access (user_key TEXT,workspace TEXT)',
            'CREATE TABLE marketplace_stores (id TEXT PRIMARY KEY, company_key TEXT, platform TEXT, store_name TEXT, store_identifier TEXT, status TEXT, authorization_status TEXT, sync_mode TEXT, default_account_id INTEGER, default_admin_fee_pct NUMERIC, default_free_shipping_pct NUMERIC, default_payment_fee_pct NUMERIC, total_transactions INTEGER DEFAULT 0, last_synced_at TEXT, archived_at TEXT, archived_by TEXT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE marketplace_oauth_connections (id INTEGER PRIMARY KEY, company_key TEXT, store_id TEXT, platform TEXT, status TEXT, authorized_at TEXT, token_expires_at TEXT, last_successful_sync_at TEXT, updated_at TEXT)',
            'CREATE TABLE marketplace_product_ads (id INTEGER PRIMARY KEY, store_id TEXT, platform TEXT, ad_type TEXT, marketplace_item_id TEXT, sku TEXT, product_name TEXT, campaign_name TEXT, image_url TEXT, period_start TEXT, period_end TEXT, ad_spend NUMERIC, gross_revenue NUMERIC, orders_count INTEGER, qty_sold INTEGER, unit_cogs_hpp NUMERIC, platform_fee NUMERIC, affiliate_fee NUMERIC, clicks INTEGER, impressions INTEGER, conversions INTEGER, is_bocor INTEGER DEFAULT 0, bocor_reason TEXT, last_synced_at TEXT)',
        ] as $sql) {
            DB::statement($sql);
        }

        DB::table('marketplace_stores')->insert([
            ['id' => 'a', 'company_key' => 'A', 'platform' => 'Shopee', 'store_name' => 'Toko A'],
            ['id' => 'b', 'company_key' => 'B', 'platform' => 'Shopee', 'store_name' => 'Toko B'],
        ]);
        $base = ['platform' => 'Shopee', 'ad_type' => 'Produk', 'period_start' => '2026-11-01', 'period_end' => '2026-11-07', 'is_bocor' => 0];
        DB::table('marketplace_product_ads')->insert($base + ['store_id' => 'a', 'marketplace_item_id' => 'I1', 'sku' => 'SKU-1', 'product_name' => 'Tas Sehat', 'ad_spend' => 100000, 'gross_revenue' => 500000, 'orders_count' => 10, 'qty_sold' => 10, 'unit_cogs_hpp' => 20000, 'platform_fee' => 25000, 'affiliate_fee' => 0]);
        DB::table('marketplace_product_ads')->insert($base + ['store_id' => 'a', 'marketplace_item_id' => 'I2', 'sku' => 'SKU-2', 'product_name' => 'Dompet Bocor', 'ad_spend' => 300000, 'gross_revenue' => 0, 'orders_count' => 0, 'qty_sold' => 0, 'unit_cogs_hpp' => 0, 'platform_fee' => 0, 'affiliate_fee' => 0]);
        DB::table('marketplace_product_ads')->insert($base + ['store_id' => 'b', 'marketplace_item_id' => 'I9', 'sku' => 'SKU-B', 'product_name' => 'Rahasia B', 'ad_spend' => 999, 'gross_revenue' => 1, 'orders_count' => 1, 'qty_sold' => 1, 'unit_cogs_hpp' => 1, 'platform_fee' => 0, 'affiliate_fee' => 0]);
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

        return $this->withHeaders($headers)->get('/marketplace/marketing'.$query);
    }

    public function test_the_page_summarises_imported_reports_and_flags_leaking_ads(): void
    {
        $response = $this->page()->assertOk()
            ->assertJsonPath('component', 'Marketplace/Marketing')
            ->assertJsonPath('props.scope.store_id', 'a')
            ->assertJsonPath('props.marketing.kpi.biaya_iklan', 400000)
            ->assertJsonPath('props.marketing.kpi.pendapatan', 500000)
            ->assertJsonPath('props.marketing.kpi.bocor_count', 1)
            ->assertJsonCount(2, 'props.marketing.products');

        $bocor = collect($response->json('props.marketing.products'))->firstWhere('sku', 'SKU-2');
        $this->assertTrue($bocor['is_bocor']);
        $this->assertNotEmpty($bocor['bocor_reason']);
    }

    public function test_the_quick_filter_and_the_ppn_toggle_apply(): void
    {
        $this->page('?filter=bocor')->assertJsonCount(1, 'props.marketing.products');
        $this->page('?include_ppn=1')->assertJsonPath('props.marketing.kpi.biaya_iklan', 444000);
    }

    public function test_another_companys_store_and_data_are_never_shown(): void
    {
        $this->page('?store_id=b')->assertForbidden();
        $this->assertStringNotContainsString('Rahasia B', $this->page()->getContent());
    }

    public function test_import_is_offered_only_to_finance_managers_and_the_template_matches_the_importer(): void
    {
        $this->page()->assertJsonPath('props.can_import', true);

        $template = $this->get('/marketplace/marketing/template')->assertOk()->streamedContent();
        $this->assertSame("marketplace_item_id,sku,product_name,campaign_name,period_start,period_end,ad_spend,gross_revenue,orders_count,qty_sold,unit_cogs_hpp,platform_fee,affiliate_fee\r\n", $template);

        $this->loginAs('ROLE-PEMBACA');
        $this->page()->assertOk()->assertJsonPath('props.can_import', false);
    }
}
