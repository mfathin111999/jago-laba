<?php

namespace Tests\Feature;

use App\Auth\AuthenticatedUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MarketplaceSettlementImportTest extends TestCase
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
            $table->string('order_status')->default('Pending');
            $table->decimal('gross_sales', 16, 2)->nullable();
            $table->decimal('net_settlement_amount', 16, 2)->nullable();
            $table->decimal('total_cogs_hpp', 16, 2)->nullable();
            $table->string('escrow_status')->nullable();
            $table->string('payout_batch_id')->nullable();
            $table->string('buyer_username')->nullable();
            $table->timestamp('order_date')->nullable();
            $table->timestamp('completion_date')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        DB::table('marketplace_stores')->insert([
            ['id' => 'a', 'company_key' => 'A', 'platform' => 'Shopee', 'store_name' => 'Toko A', 'archived_at' => null],
            ['id' => 'b', 'company_key' => 'B', 'platform' => 'Shopee', 'store_name' => 'Toko B', 'archived_at' => null],
        ]);
        DB::table('marketplace_orders')->insert([
            ['id' => 'OWN-1', 'store_id' => 'a', 'order_status' => 'Pending'],
            ['id' => 'OTHER-1', 'store_id' => 'b', 'order_status' => 'Pending'],
        ]);
        DB::table('workspace_user_access')->insert([['user_key' => 'u1', 'workspace' => 'marketplace']]);
        $this->actingAs(new AuthenticatedUser(['id' => 'u1', 'username' => 'u1', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN', 'company_key' => 'A']), 'web');
        session(['_token' => 'tok', 'workspace' => 'marketplace']);
    }

    private function upload(string $storeId, string $csv): TestResponse
    {
        return $this->withHeaders(['X-CSRF-TOKEN' => 'tok', 'Accept' => 'application/json'])->post('/api/marketplace-ajax.php?action=import_settlement_csv', [
            'store_id' => $storeId,
            'settlement_file' => UploadedFile::fake()->createWithContent('settlement.csv', $csv),
        ]);
    }

    public function test_settlement_import_stays_disabled_and_changes_nothing(): void
    {
        $before = DB::table('marketplace_orders')->orderBy('id')->get()->all();

        $this->upload('a', "order_id,net\nOWN-1,100000\nNEW-9,50000\n")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Impor settlement menunggu rekening dan rekonsiliasi Marketplace terpisah. Tidak ada data yang diubah.');

        $this->assertEquals($before, DB::table('marketplace_orders')->orderBy('id')->get()->all());
        $this->assertDatabaseMissing('marketplace_orders', ['id' => 'NEW-9']);
        $this->assertDatabaseMissing('system_audit_logs', ['action' => 'IMPORT_SETTLEMENT_CSV']);
    }

    public function test_a_csv_can_never_touch_an_order_of_another_store_or_company(): void
    {
        $this->upload('a', "order_id,net\nOTHER-1,999999\n")->assertStatus(422);

        $other = DB::table('marketplace_orders')->where('id', 'OTHER-1')->first();
        $this->assertSame('Pending', $other->order_status);
        $this->assertNull($other->net_settlement_amount);

        $this->upload('b', "order_id,net\nOTHER-1,999999\n")->assertForbidden();
        $this->assertSame('Pending', DB::table('marketplace_orders')->where('id', 'OTHER-1')->value('order_status'));
    }
}
