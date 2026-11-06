<?php

namespace Tests\Feature;

use App\Auth\AuthenticatedUser;
use App\Services\Konveksi\KonveksiInventoryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class KonveksiInventoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createAuditLogTable();
        Schema::create('system_table_map', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('sheet_name');
            $table->string('api_key');
            $table->string('table_name');
        });
        foreach (['produk', 'master_stok', 'mutasi_stok'] as $key) {
            Schema::create('jl_data_'.$key, function (Blueprint $table): void {
                $table->bigIncrements('_row_id');
                $table->unsignedInteger('_row_order')->default(0);
                $table->longText('_data_json');
            });
            DB::table('system_table_map')->insert(['sheet_name' => $key, 'api_key' => $key, 'table_name' => 'jl_data_'.$key]);
        }
    }

    /** @param array<string, mixed> $data */
    private function addRow(string $key, array $data): void
    {
        DB::table('jl_data_'.$key)->insert(['_row_order' => 0, '_data_json' => json_encode($data)]);
    }

    /** @return list<array<string, mixed>> */
    private function all(string $key): array
    {
        return DB::table('jl_data_'.$key)->orderBy('_row_id')->pluck('_data_json')->map(fn ($json) => json_decode($json, true))->all();
    }

    private function user(): AuthenticatedUser
    {
        return new AuthenticatedUser(['id' => 'u1', 'username' => 'admin', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN']);
    }

    public function test_audit_reports_a_clean_inventory_as_pass(): void
    {
        $this->addRow('produk', ['id' => 'P1', 'kode' => 'A-1', 'id_stok_item' => 'STK-1', 'stok' => 5]);
        $this->addRow('master_stok', ['id_stok_item' => 'STK-1', 'kode_sku' => 'A-1', 'stok_akhir' => 5]);

        $report = app(KonveksiInventoryService::class)->audit($this->user(), 'A');

        $this->assertSame('PASS', $report['status']);
        $this->assertSame(['missing' => 0, 'duplicate' => 0, 'negative' => 0, 'mismatch' => 0], $report['summary']);
        $this->assertDatabaseHas('system_audit_logs', ['action' => 'AUDIT_INVENTORY']);
    }

    public function test_audit_flags_missing_duplicate_negative_and_mismatched_stock(): void
    {
        $this->addRow('produk', ['id' => 'P1', 'kode' => 'A-1', 'id_stok_item' => 'STK-1', 'stok' => 5]);
        $this->addRow('produk', ['id' => 'P2', 'kode' => 'B-1', 'id_stok_item' => 'STK-1', 'stok' => 2]);
        $this->addRow('produk', ['id' => 'P3', 'kode' => 'C-1', 'stok' => 1]);
        $this->addRow('master_stok', ['id_stok_item' => 'STK-1', 'kode_sku' => 'A-1', 'stok_akhir' => 9]);
        $this->addRow('master_stok', ['id_stok_item' => 'STK-9', 'kode_sku' => 'Z-1', 'stok_akhir' => -4]);
        $this->addRow('mutasi_stok', ['id_mutasi' => 'M1']);

        $report = app(KonveksiInventoryService::class)->audit($this->user(), 'A');

        $this->assertSame('WARNING', $report['status']);
        $this->assertSame(['STK-1'], $report['duplicate_id_stok_item']);
        $this->assertSame('C-1', $report['missing_id_stok_item'][0]['kode_sku']);
        $this->assertSame(-4.0, $report['negative_stock'][0]['stok']);
        $this->assertSame('A-1', $report['produk_master_mismatch'][0]['kode_sku']);
        $this->assertSame(1, $report['total_mutasi']);
    }

    public function test_id_migration_links_master_rows_to_products_by_sku_and_is_repeatable(): void
    {
        $this->addRow('produk', ['id' => 'P1', 'kode' => 'a-1', 'stok' => 5]);
        $this->addRow('master_stok', ['kode_sku' => 'A-1', 'stok_akhir' => 5]);
        $this->addRow('master_stok', ['kode_sku' => 'LAIN', 'stok_akhir' => 1]);

        $first = app(KonveksiInventoryService::class)->migrateIds($this->user(), 'A');

        $this->assertSame(3, $first['updated']);
        $product = $this->all('produk')[0];
        $master = $this->all('master_stok');
        $this->assertStringStartsWith('STK-', $product['id_stok_item']);
        $this->assertSame($product['id_stok_item'], $master[0]['id_stok_item'], 'same SKU, same stock id');
        $this->assertNotSame($product['id_stok_item'], $master[1]['id_stok_item']);

        $second = app(KonveksiInventoryService::class)->migrateIds($this->user(), 'A');
        $this->assertSame(0, $second['updated']);
        $this->assertSame($product['id_stok_item'], $this->all('produk')[0]['id_stok_item'], 'existing ids are never replaced');
    }
}
