<?php

namespace Tests\Feature;

use App\Auth\AuthenticatedUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MarketplaceIncomePageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'CREATE TABLE workspace_user_access (user_key TEXT,workspace TEXT)',
            'CREATE TABLE marketplace_stores (id TEXT PRIMARY KEY, company_key TEXT, platform TEXT, store_name TEXT, store_identifier TEXT, status TEXT, authorization_status TEXT, sync_mode TEXT, default_account_id INTEGER, default_admin_fee_pct NUMERIC, default_free_shipping_pct NUMERIC, default_payment_fee_pct NUMERIC, total_transactions INTEGER DEFAULT 0, last_synced_at TEXT, archived_at TEXT, archived_by TEXT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE marketplace_oauth_connections (id INTEGER PRIMARY KEY, company_key TEXT, store_id TEXT, platform TEXT, status TEXT, authorized_at TEXT, token_expires_at TEXT, last_successful_sync_at TEXT, updated_at TEXT)',
            'CREATE TABLE marketplace_orders (id TEXT PRIMARY KEY, store_id TEXT, source_kind TEXT, internal_state TEXT, order_date TEXT, order_status TEXT, gross_sales NUMERIC, payout_batch_id TEXT, transaction_detail_json TEXT)',
            'CREATE TABLE marketplace_withdrawals (id INTEGER PRIMARY KEY, company_key TEXT, store_id TEXT, external_id TEXT, withdrawal_type TEXT, requested_at TEXT, completed_at TEXT, amount NUMERIC, fee NUMERIC, net_amount NUMERIC, currency TEXT, source_status TEXT, normalized_status TEXT, bank_name TEXT, account_mask TEXT, failure_reason TEXT, source_kind TEXT, source_hash TEXT, row_hash TEXT, imported_by TEXT, imported_at TEXT, updated_at TEXT)',
        ] as $sql) {
            DB::statement($sql);
        }

        DB::table('marketplace_stores')->insert([
            ['id' => 'a', 'company_key' => 'A', 'platform' => 'TikTok Shop', 'store_name' => 'Toko A'],
            ['id' => 'b', 'company_key' => 'B', 'platform' => 'TikTok Shop', 'store_name' => 'Toko B'],
        ]);
        $orders = [
            ['id' => 'FINAL-1', 'store_id' => 'a', 'order_date' => '2026-11-02 10:00:00', 'net' => ['gross_amount' => 100000, 'total_fees' => 10000, 'net_income' => 90000, 'is_estimated' => false]],
            ['id' => 'EST-1', 'store_id' => 'a', 'order_date' => '2026-11-03 10:00:00', 'net' => ['gross_amount' => 200000, 'total_fees' => 20000, 'net_income' => 180000, 'is_estimated' => true]],
            ['id' => 'NODATA-1', 'store_id' => 'a', 'order_date' => '2026-11-03 11:00:00', 'net' => ['gross_amount' => 50000, 'total_fees' => null, 'net_income' => null]],
            ['id' => 'OTHER-B', 'store_id' => 'b', 'order_date' => '2026-11-03 12:00:00', 'net' => ['gross_amount' => 777, 'net_income' => 777, 'is_estimated' => false]],
        ];
        foreach ($orders as $order) {
            DB::table('marketplace_orders')->insert([
                'id' => $order['id'], 'store_id' => $order['store_id'], 'source_kind' => 'api', 'order_date' => $order['order_date'], 'order_status' => 'COMPLETED',
                'transaction_detail_json' => json_encode($order['net'] + ['secret_internal' => 'JANGAN-BOCOR']),
            ]);
        }
        DB::table('marketplace_withdrawals')->insert([
            ['company_key' => 'A', 'store_id' => 'a', 'external_id' => 'WD-1', 'requested_at' => '2026-11-02 09:00:00', 'completed_at' => '2026-11-02 12:00:00', 'amount' => 500000, 'fee' => 1000, 'net_amount' => 499000, 'currency' => 'IDR', 'source_status' => 'PAID', 'normalized_status' => 'succeeded', 'bank_name' => 'BCA', 'account_mask' => '•••• 1234', 'source_kind' => 'import', 'source_hash' => 'h', 'row_hash' => 'r', 'imported_by' => 'u1'],
        ]);
        DB::table('marketplace_withdrawals')->insert([
            ['company_key' => 'B', 'store_id' => 'b', 'external_id' => 'WD-B', 'requested_at' => '2026-11-02 09:00:00', 'amount' => 999, 'currency' => 'IDR', 'source_status' => 'PAID', 'normalized_status' => 'succeeded', 'source_kind' => 'import', 'source_hash' => 'h', 'row_hash' => 'r2', 'imported_by' => 'x'],
        ]);
        DB::table('workspace_user_access')->insert([['user_key' => 'u1', 'workspace' => 'marketplace']]);
        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');
    }

    private function loginAs(string $role): void
    {
        $this->actingAs(new AuthenticatedUser(['id' => 'u1', 'username' => 'u1', 'role_id' => $role, 'company_key' => 'A']), 'web');
        session(['_token' => 'tok', 'workspace' => 'marketplace']);
    }

    private function page(string $query = '?date_start=2026-11-01&date_end=2026-11-30'): TestResponse
    {
        $headers = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml'];
        if (is_file(public_path('build/manifest.json'))) {
            $headers['X-Inertia-Version'] = hash_file('xxh128', public_path('build/manifest.json'));
        }

        return $this->withHeaders($headers)->get('/marketplace/income'.$query);
    }

    public function test_income_is_split_into_final_estimated_and_unknown_without_inventing_numbers(): void
    {
        $response = $this->page()->assertOk()
            ->assertJsonPath('component', 'Marketplace/Income')
            ->assertJsonPath('props.income.summary.final', 90000)
            ->assertJsonPath('props.income.summary.estimated', 180000)
            ->assertJsonPath('props.income.summary.unknown', 1)
            ->assertJsonPath('props.income.total', 3);

        $rows = collect($response->json('props.income.rows'))->keyBy('order_id');
        $this->assertSame('final', $rows['FINAL-1']['state']);
        $this->assertSame('estimated', $rows['EST-1']['state']);
        $this->assertSame('unknown', $rows['NODATA-1']['state']);
        $this->assertNull($rows['NODATA-1']['net_income'], 'an order without income data stays unknown, never zero');
    }

    public function test_other_companies_and_raw_snapshots_never_reach_the_browser(): void
    {
        $body = $this->page()->getContent();

        foreach (['OTHER-B', 'WD-B', 'JANGAN-BOCOR', 'transaction_detail_json', 'Toko B'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    public function test_the_status_filter_and_store_scope_apply(): void
    {
        $this->page('?date_start=2026-11-01&date_end=2026-11-30&status=estimated')->assertJsonCount(1, 'props.income.rows');
        $this->page('?date_start=2026-11-01&date_end=2026-11-30&store_id=b')->assertForbidden();
    }

    public function test_withdrawal_history_shows_masked_destinations_only(): void
    {
        $response = $this->page('?tab=payout&date_start=2026-11-01&date_end=2026-11-30')->assertOk()
            ->assertJsonCount(1, 'props.withdrawals.rows')
            ->assertJsonPath('props.withdrawals.rows.0.external_id', 'WD-1')
            ->assertJsonPath('props.withdrawals.rows.0.account_mask', '•••• 1234')
            ->assertJsonPath('props.withdrawals.summary.total', 1);

        $this->assertSame('499000.00', (string) number_format((float) $response->json('props.withdrawals.rows.0.net_amount'), 2, '.', ''));
    }

    public function test_only_withdrawal_managers_get_the_import_form(): void
    {
        $this->page()->assertJsonPath('props.can_import', true);

        $this->loginAs('ROLE-PEMBACA');
        $this->page()->assertOk()->assertJsonPath('props.can_import', false);
    }
}
