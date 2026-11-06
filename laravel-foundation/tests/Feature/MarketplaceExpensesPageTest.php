<?php

namespace Tests\Feature;

use App\Auth\AuthenticatedUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MarketplaceExpensesPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createWorkspaceAccessTable();
        $this->createAuditLogTable();
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('company_key')->nullable();
            $table->string('idempotency_key');
            $table->string('module')->nullable();
            $table->string('reference_id')->nullable();
            $table->text('response_json')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->unique(['company_key', 'module', 'idempotency_key'], 'uq_idempotency_scope');
        });
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
            $table->text('access_token_encrypted')->nullable();
            $table->text('refresh_token_encrypted')->nullable();
            $table->dateTime('authorized_at')->nullable();
            $table->dateTime('token_expires_at')->nullable();
            $table->dateTime('last_successful_sync_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        Schema::create('marketplace_expenses', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('store_id');
            $table->date('expense_date');
            $table->string('category')->nullable();
            $table->string('description')->nullable();
            $table->string('vendor_name')->nullable();
            $table->decimal('amount', 18, 2)->nullable();
            $table->string('payment_method')->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('affects_profit')->default(true);
            $table->boolean('is_cashflow_only')->default(false);
            $table->dateTime('created_at')->nullable();
        });

        DB::table('marketplace_stores')->insert([
            ['id' => 'a', 'company_key' => 'A', 'platform' => 'Shopee', 'store_name' => 'Toko A', 'status' => 'Terhubung'],
            ['id' => 'b', 'company_key' => 'B', 'platform' => 'Shopee', 'store_name' => 'Toko B', 'status' => 'Terhubung'],
        ]);
        DB::table('marketplace_expenses')->insert([
            ['store_id' => 'a', 'expense_date' => '2026-10-02', 'category' => 'Iklan', 'description' => 'Iklan Oktober', 'vendor_name' => 'Vendor', 'amount' => 100000, 'affects_profit' => 1, 'is_cashflow_only' => 0],
            ['store_id' => 'a', 'expense_date' => '2026-10-03', 'category' => 'Kas', 'description' => 'Setor kas', 'vendor_name' => 'Vendor', 'amount' => 50000, 'affects_profit' => 0, 'is_cashflow_only' => 1],
            ['store_id' => 'b', 'expense_date' => '2026-10-03', 'category' => 'Iklan', 'description' => 'Rahasia perusahaan B', 'vendor_name' => 'Vendor', 'amount' => 999, 'affects_profit' => 1, 'is_cashflow_only' => 0],
        ]);
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

        return $this->withHeaders($headers)->get('/marketplace/expenses'.$query);
    }

    public function test_the_page_falls_back_to_the_first_store_and_summarises_its_expenses(): void
    {
        $this->page()->assertOk()
            ->assertJsonPath('component', 'Marketplace/Expenses')
            ->assertJsonPath('props.scope.store_id', 'a')
            ->assertJsonPath('props.can_manage', true)
            ->assertJsonCount(2, 'props.expenses')
            ->assertJsonPath('props.summary.count', 2)
            ->assertJsonPath('props.summary.cashflow_only', 50000);

        $this->assertStringNotContainsString('Rahasia perusahaan B', $this->page('?store_id=a')->getContent());
    }

    public function test_another_companys_store_is_refused(): void
    {
        $this->page('?store_id=b')->assertForbidden();
    }

    public function test_the_filters_and_csv_export_still_work(): void
    {
        $this->page('?store_id=a&search=Setor')->assertJsonCount(1, 'props.expenses');

        $csv = $this->get('/marketplace/expenses?store_id=a&format=csv')->assertOk();
        $this->assertStringContainsString('Iklan Oktober', $csv->streamedContent());
    }

    public function test_the_page_creates_and_deletes_expenses_over_json(): void
    {
        $payload = ['store_id' => 'a', 'description' => 'Biaya baru', 'amount' => 'Rp 12.500', 'expense_date' => '2026-10-05', 'category' => 'Iklan'];

        $created = $this->withHeaders(['X-CSRF-TOKEN' => 'tok', 'X-Idempotency-Key' => 'expense-page-key-0001'])
            ->postJson('/pilot/marketplace/expenses', $payload)->assertOk()->assertJsonPath('success', true);
        $this->assertSame(1, DB::table('marketplace_expenses')->where('description', 'Biaya baru')->count());
        $this->assertEquals(12500, DB::table('marketplace_expenses')->where('description', 'Biaya baru')->value('amount'));

        $this->withHeaders(['X-CSRF-TOKEN' => 'tok', 'X-Idempotency-Key' => 'expense-page-key-0001'])
            ->postJson('/pilot/marketplace/expenses', $payload)->assertOk();
        $this->assertSame(1, DB::table('marketplace_expenses')->where('description', 'Biaya baru')->count(), 'a retry with the same key never duplicates');

        $this->withHeaders(['X-CSRF-TOKEN' => 'tok', 'X-Idempotency-Key' => 'expense-page-key-0002'])
            ->postJson('/pilot/marketplace/expenses', ['store_id' => 'a', 'description' => '', 'amount' => '0'])
            ->assertStatus(422)->assertJsonPath('success', false);

        $id = DB::table('marketplace_expenses')->where('description', 'Biaya baru')->value('id');
        $this->withHeader('X-CSRF-TOKEN', 'tok')->deleteJson("/pilot/marketplace/expenses/{$id}?store_id=a")->assertOk();
        $this->assertSame(0, DB::table('marketplace_expenses')->where('id', $id)->count());
        $this->assertNotNull($created->json());
    }

    public function test_a_reader_can_see_but_not_change_expenses(): void
    {
        $this->loginAs('ROLE-PEMBACA');
        $this->page('?store_id=a')->assertOk()->assertJsonPath('props.can_manage', false);
        $this->withHeaders(['X-CSRF-TOKEN' => 'tok', 'X-Idempotency-Key' => 'expense-page-key-0003'])
            ->postJson('/pilot/marketplace/expenses', ['store_id' => 'a', 'description' => 'Tidak boleh', 'amount' => '1000'])->assertForbidden();
        $this->assertSame(0, DB::table('marketplace_expenses')->where('description', 'Tidak boleh')->count());
    }
}
