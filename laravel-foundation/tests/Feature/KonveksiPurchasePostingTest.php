<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use App\Pilots\Konveksi\KonveksiPurchaseWriteService;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class KonveksiPurchasePostingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('system_audit_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            foreach (['event_id', 'event_time', 'user_id', 'username', 'module', 'action', 'record_id', 'reference_id', 'before_json', 'after_json', 'status', 'notes'] as $column) {
                $table->text($column)->nullable();
            }
        });
        Schema::create('system_table_map', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('sheet_name');
            $table->string('api_key');
            $table->string('table_name');
        });
        foreach (['pembelian', 'pembelian_item', 'bahan', 'bahan_hpp', 'mutasi_stok', 'keuangan', 'hutangsupplier'] as $key) {
            Schema::create('jl_data_'.$key, function (Blueprint $table): void {
                $table->bigIncrements('_row_id');
                $table->unsignedInteger('_row_order')->default(0);
                $table->longText('_data_json');
            });
            DB::table('system_table_map')->insert(['sheet_name' => $key, 'api_key' => $key, 'table_name' => 'jl_data_'.$key]);
        }

        $this->addRow('bahan', ['id_bahan' => 'B1', 'id' => 'B1', 'kode_bahan' => 'KAIN-1', 'nama_bahan' => 'Kain Katun', 'stok' => 10, 'harga_beli' => 40000]);
        $this->addRow('bahan', ['id_bahan' => 'B2', 'id' => 'B2', 'kode_bahan' => 'BTN-1', 'nama_bahan' => 'Kancing', 'stok' => 100, 'harga_beli' => 100]);
        $this->addRow('bahan_hpp', ['id_bahan_hpp' => 'HPP-B1', 'id_bahan' => 'B1', 'harga_aktif' => 4000]);
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

    private function material(string $id): array
    {
        return collect($this->all('bahan'))->firstWhere('id_bahan', $id);
    }

    private function service(): KonveksiPurchaseWriteService
    {
        return app(KonveksiPurchaseWriteService::class);
    }

    private function user(): LegacyUser
    {
        return new LegacyUser(['id' => 'u1', 'username' => 'gudang', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN']);
    }

    /** @return array<string, mixed> */
    private function invoice(array $overrides = []): array
    {
        return $overrides + [
            'id_transaksi' => 'PBL-1', 'no_faktur_supplier' => 'INV-001', 'id_supplier' => 'SUP-1', 'nama_supplier' => 'Toko Kain', 'tanggal' => '2026-11-04',
            'id_rekening' => 'KAS-1', 'nominal_bayar' => 500000, 'jatuh_tempo' => '2026-12-04',
            'items' => [
                ['id_bahan' => 'B1', 'qty' => 5, 'conversion_factor' => 40, 'harga_satuan' => 200000, 'subtotal' => 1000000],
                ['id_bahan' => 'B2', 'qty' => 2, 'qty_satuan_pakai' => 500, 'harga_satuan' => 50000, 'subtotal' => 100000],
            ],
        ];
    }

    public function test_posting_an_invoice_adds_stock_records_movements_cash_and_supplier_debt(): void
    {
        $result = $this->service()->saveInvoice($this->user(), 'A', $this->invoice());

        $this->assertSame('success', $result['status']);
        $this->assertSame(2, $result['items_count']);
        $this->assertEquals(1100000, $result['purchase']['total_harga'], 'sum of the line subtotals');

        $this->assertEquals(210, $this->material('B1')['stok'], '10 + 5 rolls x 40');
        $this->assertEquals(600, $this->material('B2')['stok'], '100 + 500 pieces');
        $this->assertEquals(200000, $this->material('B1')['harga_beli']);

        $movements = $this->all('mutasi_stok');
        $this->assertSame(['IN_PURCHASE', 'IN_PURCHASE'], array_column($movements, 'jenis_mutasi'));
        $this->assertEquals([10, 200, 210], [$movements[0]['stok_sebelum'], $movements[0]['qty_masuk'], $movements[0]['stok_setelah']]);
        $this->assertSame('INV-001', $movements[0]['no_referensi']);

        $price = $this->all('bahan_hpp')[0];
        $this->assertEquals(200000, $price['harga_beli_terakhir']);
        $this->assertTrue($price['price_review_required'], 'HPP must be reviewed after a new purchase price');
        $this->assertEquals(4000, $price['harga_aktif'], 'the active HPP price is not changed automatically');

        $cash = $this->all('keuangan');
        $this->assertCount(1, $cash);
        $this->assertSame('Keluar', $cash[0]['tipe']);
        $this->assertEquals(500000, $cash[0]['nominal']);

        $debt = $this->all('hutangsupplier');
        $this->assertCount(1, $debt);
        $this->assertEquals(600000, $debt[0]['saldo_hutang'], '1,100,000 - 500,000 paid');
        $this->assertSame('SUP-1', $debt[0]['id_supplier']);
        $this->assertSame('Aktif', $debt[0]['status']);

        $this->assertCount(2, $this->all('pembelian_item'));
        $this->assertCount(1, $this->all('pembelian'));
        $this->assertDatabaseHas('system_audit_logs', ['action' => 'POST_PURCHASE', 'record_id' => 'PBL-1']);
    }

    public function test_fully_paid_has_no_debt_and_fully_unpaid_has_no_cash_entry(): void
    {
        $this->service()->saveInvoice($this->user(), 'A', $this->invoice(['nominal_bayar' => 1100000]));
        $this->assertSame([], $this->all('hutangsupplier'));
        $this->assertCount(1, $this->all('keuangan'));

        $this->service()->saveInvoice($this->user(), 'A', $this->invoice(['id_transaksi' => 'PBL-2', 'no_faktur_supplier' => 'INV-002', 'nominal_bayar' => 0]));
        $this->assertCount(1, $this->all('keuangan'), 'no payment, no cash entry');
        $this->assertEquals(1100000, $this->all('hutangsupplier')[0]['saldo_hutang']);
    }

    public function test_reposting_is_ignored_but_changing_a_posted_invoice_or_reusing_its_number_is_refused(): void
    {
        $this->service()->saveInvoice($this->user(), 'A', $this->invoice());

        $again = $this->service()->saveInvoice($this->user(), 'A', $this->invoice());
        $this->assertSame('DUPLICATE_IGNORED', $again['code']);
        $this->assertEquals(210, $this->material('B1')['stok'], 'stock is added once');
        $this->assertCount(2, $this->all('mutasi_stok'));

        try {
            $this->service()->saveInvoice($this->user(), 'A', $this->invoice(['nominal_bayar' => 0]));
            $this->fail('A posted invoice must not be overwritten.');
        } catch (DomainException $exception) {
            $this->assertSame(409, $exception->getCode());
        }

        try {
            $this->service()->saveInvoice($this->user(), 'A', $this->invoice(['id_transaksi' => 'PBL-9', 'no_faktur_supplier' => 'inv-001']));
            $this->fail('The same supplier invoice number must not be booked twice.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Faktur supplier ini sudah pernah dicatat.', $exception->getMessage());
        }

        $this->service()->saveInvoice($this->user(), 'A', $this->invoice(['id_transaksi' => 'PBL-3', 'id_supplier' => 'SUP-2']));
        $this->assertCount(2, $this->all('pembelian'), 'the same number from another supplier is fine');
    }

    public function test_invalid_invoices_are_refused_before_anything_is_written(): void
    {
        $cases = [
            [InvalidArgumentException::class, ['items' => []]],
            [RuntimeException::class, ['items' => [['qty' => 1, 'harga_satuan' => 1]]]],
            [RuntimeException::class, ['items' => [['id_bahan' => 'TIDAK-ADA', 'qty' => 1, 'harga_satuan' => 1]]]],
            [InvalidArgumentException::class, ['items' => [['id_bahan' => 'B1', 'qty' => 0, 'harga_satuan' => 1]]]],
            [InvalidArgumentException::class, ['items' => [['id_bahan' => 'B1', 'qty' => 1, 'harga_satuan' => -1]]]],
            [InvalidArgumentException::class, ['nominal_bayar' => 99999999]],
            [InvalidArgumentException::class, ['nominal_bayar' => -1]],
            [InvalidArgumentException::class, ['id_rekening' => '']],
        ];

        foreach ($cases as [$exception, $override]) {
            try {
                $this->service()->saveInvoice($this->user(), 'A', $this->invoice($override));
                $this->fail('Expected '.$exception.' for '.json_encode($override));
            } catch (\Throwable $caught) {
                $this->assertInstanceOf($exception, $caught, json_encode($override).' -> '.$caught->getMessage());
            }
        }

        $this->assertEquals(10, $this->material('B1')['stok']);
        $this->assertEquals(100, $this->material('B2')['stok']);
        foreach (['pembelian', 'pembelian_item', 'mutasi_stok', 'keuangan', 'hutangsupplier'] as $key) {
            $this->assertSame([], $this->all($key), $key.' must stay empty');
        }
    }

    public function test_a_later_line_failing_does_not_leave_earlier_lines_applied(): void
    {
        try {
            $this->service()->saveInvoice($this->user(), 'A', $this->invoice(['items' => [
                ['id_bahan' => 'B1', 'qty' => 1, 'conversion_factor' => 10, 'harga_satuan' => 1000],
                ['id_bahan' => 'B2', 'qty' => 0, 'harga_satuan' => 1000],
            ]]));
            $this->fail('The second line is invalid.');
        } catch (InvalidArgumentException) {
        }

        $this->assertEquals(10, $this->material('B1')['stok'], 'the first line must not be applied');
        $this->assertSame([], $this->all('mutasi_stok'));
    }

    public function test_unpaid_remainder_needs_the_supplier_debt_collection_to_exist(): void
    {
        DB::table('system_table_map')->where('api_key', 'hutangsupplier')->delete();

        try {
            $this->service()->saveInvoice($this->user(), 'A', $this->invoice());
            $this->fail('A debt that cannot be recorded must stop the posting.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('hutang supplier', $exception->getMessage());
        }

        $this->assertEquals(10, $this->material('B1')['stok']);
        $this->assertSame([], $this->all('keuangan'));
    }

    public function test_the_supplier_debt_collection_is_found_under_the_name_the_snapshot_importer_gave_it(): void
    {
        DB::table('system_table_map')->where('api_key', 'hutangsupplier')->update(['api_key' => 'hutang_supplier']);

        $result = $this->service()->saveInvoice($this->user(), 'A', $this->invoice());

        $this->assertSame('success', $result['status']);
        $this->assertCount(1, $this->all('hutangsupplier'), 'the unpaid part is booked as supplier debt even when the map says hutang_supplier');
    }

    public function test_new_material_gets_a_price_record_and_an_opening_stock_movement(): void
    {
        $result = $this->service()->saveMaterial($this->user(), 'A', [
            'nama_bahan' => 'Benang', 'kode_bahan' => 'bn-1', 'stok_awal' => 25, 'harga_beli' => 12000, 'isi_satuan_beli' => 4,
        ]);

        $this->assertSame('create', $result['action']);
        $material = collect($this->all('bahan'))->firstWhere('kode_bahan', 'BN-1');
        $this->assertEquals(25, $material['stok']);
        $this->assertEquals(3000, $this->all('bahan_hpp')[1]['harga_satuan_pakai'], '12,000 per 4 units');
        $movement = $this->all('mutasi_stok')[0];
        $this->assertSame('IN_OPENING', $movement['jenis_mutasi']);
        $this->assertEquals(25, $movement['stok_setelah']);

        $this->expectException(RuntimeException::class);
        $this->service()->saveMaterial($this->user(), 'A', ['nama_bahan' => 'Lain', 'kode_bahan' => 'BN-1', 'stok_awal' => 1]);
    }
}
