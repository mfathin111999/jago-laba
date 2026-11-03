<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use App\Pilots\Konveksi\KonveksiSalesWriteService;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class KonveksiSalesPostingTest extends TestCase
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
        foreach (['penjualan', 'penjualan_item', 'master_stok', 'produk', 'mutasi_stok', 'keuangan', 'penjualan_koreksi'] as $key) {
            Schema::create('jl_data_'.$key, function (Blueprint $table): void {
                $table->bigIncrements('_row_id');
                $table->unsignedInteger('_row_order')->default(0);
                $table->longText('_data_json');
            });
            DB::table('system_table_map')->insert(['sheet_name' => $key, 'api_key' => $key, 'table_name' => 'jl_data_'.$key]);
        }

        $this->addRow('produk', ['id' => 'P1', 'id_stok_item' => 'STK-1', 'kode' => 'KMJ-01', 'nama' => 'Kemeja', 'stok' => 10, 'hpp_produk' => 50000]);
        $this->addRow('produk', ['id' => 'P2', 'id_stok_item' => 'STK-2', 'kode' => 'CLN-01', 'nama' => 'Celana', 'stok' => 5]);
        $this->addRow('master_stok', ['id_stok_item' => 'STK-1', 'id_produk' => 'P1', 'kode_sku' => 'KMJ-01', 'nama_produk' => 'Kemeja', 'stok_akhir' => 10, 'stok' => 10, 'keluar_penjualan' => 0, 'stok_minimum' => 3]);
        $this->addRow('master_stok', ['id_stok_item' => 'STK-2', 'id_produk' => 'P2', 'kode_sku' => 'CLN-01', 'nama_produk' => 'Celana', 'stok_akhir' => 5, 'stok' => 5, 'keluar_penjualan' => 0]);
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

    /** @return array<string, mixed> */
    private function stock(string $key, string $field = 'stok_akhir'): mixed
    {
        return collect($this->all('master_stok'))->firstWhere('id_stok_item', $key)[$field];
    }

    private function service(): KonveksiSalesWriteService
    {
        return app(KonveksiSalesWriteService::class);
    }

    private function user(): LegacyUser
    {
        return new LegacyUser(['id' => 'u1', 'username' => 'kasir', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN']);
    }

    /** @return array<string, mixed> */
    private function sale(array $overrides = []): array
    {
        return $overrides + [
            'tanggal' => '2026-11-04', 'id_rekening' => 'KAS-1', 'diskon_transaksi' => 0, 'ongkir' => 5000, 'nominal_dibayar' => 300000,
            'items' => [['kode_sku' => 'KMJ-01', 'nama_produk' => 'Kemeja', 'qty' => 3, 'harga_jual' => 100000, 'diskon_item' => 10000]],
        ];
    }

    public function test_posting_deducts_stock_records_the_movement_and_books_the_payment(): void
    {
        $result = $this->service()->post($this->user(), 'A', $this->sale());
        $sale = $result['data'];

        $this->assertSame('SALE_POSTED', $result['code']);
        $this->assertSame('POSTED', $sale['status_transaksi']);
        $this->assertEquals(300000, $sale['subtotal']);
        $this->assertEquals(10000, $sale['diskon_item_total']);
        $this->assertEquals(295000, $sale['total_tagihan'], '(300000 - 10000) + 5000 shipping');
        $this->assertEquals(150000, $sale['total_hpp']);
        $this->assertEquals(140000, $sale['laba_kotor'], 'goods 290000 - HPP 150000');
        $this->assertEquals(295000, $sale['nominal_dibayar'], 'payment is capped at the bill');
        $this->assertEquals(5000, $sale['kembalian']);
        $this->assertEquals(0, $sale['sisa_tagihan']);

        $this->assertEquals(7, $this->stock('STK-1'));
        $this->assertEquals(3, $this->stock('STK-1', 'keluar_penjualan'));
        $this->assertSame('Aman', $this->stock('STK-1', 'status_stok'));
        $this->assertEquals(7, collect($this->all('produk'))->firstWhere('id', 'P1')['stok']);

        $movement = $this->all('mutasi_stok')[0];
        $this->assertSame('OUT_SALE', $movement['jenis_mutasi']);
        $this->assertEquals([10, 3, 7], [$movement['stok_sebelum'], $movement['qty_keluar'], $movement['stok_setelah']]);
        $this->assertSame($sale['no_penjualan'], $movement['no_referensi']);

        $cash = $this->all('keuangan');
        $this->assertCount(1, $cash);
        $this->assertSame('Masuk', $cash[0]['tipe']);
        $this->assertEquals(295000, $cash[0]['nominal']);
        $this->assertSame('KAS-1', $cash[0]['id_rekening']);

        $item = $this->all('penjualan_item')[0];
        $this->assertEquals(50000, $item['hpp_satuan']);
        $this->assertEquals(290000, $item['subtotal']);
        $this->assertDatabaseHas('system_audit_logs', ['action' => 'POST_SALE']);
    }

    public function test_figures_sent_by_the_screen_are_never_trusted(): void
    {
        $sale = $this->service()->post($this->user(), 'A', $this->sale([
            'total_tagihan' => 1, 'laba_kotor' => 9999999, 'total_hpp' => 0, 'subtotal' => 1, 'operator' => 'penyusup', 'posted_by' => 'penyusup',
        ]))['data'];

        $this->assertEquals(295000, $sale['total_tagihan']);
        $this->assertEquals(140000, $sale['laba_kotor']);
        $this->assertEquals(150000, $sale['total_hpp']);
        $this->assertSame('kasir', $sale['posted_by']);
        $this->assertSame('kasir', $sale['operator']);
        $this->assertSame('kasir', $this->all('mutasi_stok')[0]['operator']);
    }

    public function test_selling_more_than_the_stock_is_refused_and_nothing_changes(): void
    {
        foreach ([
            [['kode_sku' => 'KMJ-01', 'qty' => 11, 'harga_jual' => 1000]],
            [['kode_sku' => 'KMJ-01', 'qty' => 6, 'harga_jual' => 1000], ['kode_sku' => 'KMJ-01', 'qty' => 6, 'harga_jual' => 1000]],
        ] as $items) {
            try {
                $this->service()->post($this->user(), 'A', $this->sale(['items' => $items, 'nominal_dibayar' => 0]));
                $this->fail('Overselling must be refused.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Stok tidak mencukupi', $exception->getMessage());
            }
        }

        $this->assertEquals(10, $this->stock('STK-1'));
        $this->assertSame([], $this->all('penjualan'));
        $this->assertSame([], $this->all('mutasi_stok'));
        $this->assertSame([], $this->all('keuangan'));
    }

    public function test_invalid_lines_and_amounts_are_refused_before_anything_is_written(): void
    {
        $cases = [
            [RuntimeException::class, ['items' => [['kode_sku' => 'KMJ-01', 'qty' => 0, 'harga_jual' => 1]]]],
            [RuntimeException::class, ['items' => [['kode_sku' => 'KMJ-01', 'qty' => 1.5, 'harga_jual' => 1]]]],
            [RuntimeException::class, ['items' => [['kode_sku' => 'TIDAK-ADA', 'qty' => 1, 'harga_jual' => 1]]]],
            [RuntimeException::class, ['items' => []]],
            [InvalidArgumentException::class, ['items' => [['kode_sku' => 'KMJ-01', 'qty' => 1, 'harga_jual' => -5]]]],
            [InvalidArgumentException::class, ['items' => [['kode_sku' => 'KMJ-01', 'qty' => 1, 'harga_jual' => 1000, 'diskon_item' => 5000]]]],
            [InvalidArgumentException::class, ['diskon_transaksi' => 999999999, 'nominal_dibayar' => 0]],
            [InvalidArgumentException::class, ['ongkir' => -1]],
            [InvalidArgumentException::class, ['id_rekening' => '']],
        ];

        foreach ($cases as [$exception, $override]) {
            try {
                $this->service()->post($this->user(), 'A', $this->sale($override));
                $this->fail('Expected '.$exception);
            } catch (\Throwable $caught) {
                $this->assertInstanceOf($exception, $caught, json_encode($override).' -> '.$caught->getMessage());
            }
        }

        $this->assertEquals(10, $this->stock('STK-1'));
        $this->assertSame([], $this->all('penjualan'));
        $this->assertSame([], $this->all('keuangan'));
    }

    public function test_sale_numbers_are_sequential_per_day_and_posting_twice_does_not_deduct_twice(): void
    {
        $prefix = 'PJL-'.date('Ymd').'-';
        $first = $this->service()->post($this->user(), 'A', $this->sale(['nominal_dibayar' => 0, 'no_penjualan' => 'DRAFT-20261104-101010']))['data'];
        $second = $this->service()->post($this->user(), 'A', $this->sale(['nominal_dibayar' => 0, 'items' => [['kode_sku' => 'CLN-01', 'qty' => 1, 'harga_jual' => 1000]]]))['data'];

        $this->assertSame($prefix.'0001', $first['no_penjualan']);
        $this->assertSame($prefix.'0002', $second['no_penjualan']);

        $again = $this->service()->post($this->user(), 'A', $this->sale(['id_penjualan' => $first['id_penjualan'], 'nominal_dibayar' => 0]));
        $this->assertSame('DUPLICATE_IGNORED', $again['code']);
        $this->assertEquals(7, $this->stock('STK-1'), 'stock is deducted once');
        $this->assertCount(2, $this->all('mutasi_stok'));
    }

    public function test_unknown_hpp_stays_unknown_instead_of_becoming_zero_profit(): void
    {
        $sale = $this->service()->post($this->user(), 'A', $this->sale([
            'nominal_dibayar' => 0, 'items' => [['kode_sku' => 'CLN-01', 'qty' => 1, 'harga_jual' => 80000]],
        ]))['data'];

        $this->assertNull($sale['total_hpp']);
        $this->assertNull($sale['laba_kotor']);
        $this->assertNull($sale['margin_persen']);
        $this->assertNull($this->all('penjualan_item')[0]['hpp_satuan']);
        $this->assertEquals(80000 + 5000, $sale['total_tagihan']);
        $this->assertEquals(85000, $sale['sisa_tagihan']);
        $this->assertSame([], $this->all('keuangan'), 'no payment, no cash entry');
    }

    public function test_low_stock_is_flagged_and_a_missing_stock_row_is_created_from_the_product(): void
    {
        $this->service()->post($this->user(), 'A', $this->sale(['nominal_dibayar' => 0, 'items' => [['kode_sku' => 'KMJ-01', 'qty' => 8, 'harga_jual' => 1000]]]));
        $this->assertSame('Menipis', $this->stock('STK-1', 'status_stok'));

        DB::table('jl_data_master_stok')->where('_data_json', 'like', '%STK-2%')->delete();
        $this->service()->post($this->user(), 'A', $this->sale(['nominal_dibayar' => 0, 'items' => [['kode_sku' => 'CLN-01', 'qty' => 5, 'harga_jual' => 1000]]]));
        $this->assertEquals(0, $this->stock('STK-2'));
        $this->assertSame('Habis', $this->stock('STK-2', 'status_stok'));
    }

    public function test_voiding_returns_the_stock_and_reverses_the_money(): void
    {
        $sale = $this->service()->post($this->user(), 'A', $this->sale())['data'];

        $result = $this->service()->void($this->user(), 'A', ['id_penjualan' => $sale['id_penjualan'], 'void_reason' => 'Salah input']);

        $this->assertSame('VOID', $result['data']['status_transaksi']);
        $this->assertSame('Salah input', $result['data']['void_reason']);
        $this->assertSame('kasir', $result['data']['void_by']);
        $this->assertEquals(10, $this->stock('STK-1'));
        $this->assertEquals(0, $this->stock('STK-1', 'keluar_penjualan'));
        $this->assertEquals(10, collect($this->all('produk'))->firstWhere('id', 'P1')['stok']);

        $movements = $this->all('mutasi_stok');
        $this->assertSame(['OUT_SALE', 'SALE_VOID_IN'], array_column($movements, 'jenis_mutasi'));
        $this->assertEquals(3, $movements[1]['qty_masuk']);

        $cash = $this->all('keuangan');
        $this->assertSame(['Masuk', 'Keluar'], array_column($cash, 'tipe'));
        $this->assertEquals(295000, $cash[1]['nominal']);

        $this->assertSame('DUPLICATE_IGNORED', $this->service()->void($this->user(), 'A', ['id_penjualan' => $sale['id_penjualan']])['code']);
        $this->assertEquals(10, $this->stock('STK-1'), 'a second void must not add stock again');
        $this->assertCount(2, $this->all('keuangan'));
    }

    public function test_drafts_cannot_be_voided_and_unknown_sales_are_reported(): void
    {
        $draft = $this->service()->saveDraft($this->user(), 'A', $this->sale(['id_penjualan' => 'DRAFT-1']))['data'];
        $this->assertSame('DRAFT', $draft['status_transaksi']);

        try {
            $this->service()->void($this->user(), 'A', ['id_penjualan' => 'DRAFT-1']);
            $this->fail('A draft has nothing to reverse.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Draft belum memiliki stok', $exception->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->service()->void($this->user(), 'A', ['id_penjualan' => 'TIDAK-ADA']);
    }

    public function test_a_posted_sale_cannot_be_changed_through_a_correction_but_notes_can(): void
    {
        $sale = $this->service()->post($this->user(), 'A', $this->sale())['data'];
        $id = $sale['id_penjualan'];

        foreach ([['nominal_dibayar' => 1], ['tanggal' => '2020-01-01'], ['id_rekening' => 'LAIN'], ['items' => []], ['total_tagihan' => 5], ['status_transaksi' => 'DRAFT']] as $change) {
            try {
                $this->service()->correct($this->user(), 'A', ['id_penjualan' => $id] + $change);
                $this->fail('Money fields of a posted sale are locked: '.json_encode($change));
            } catch (DomainException $exception) {
                $this->assertSame(409, $exception->getCode());
            }
        }

        $first = $this->service()->correct($this->user(), 'A', ['id_penjualan' => $id, 'catatan' => 'Pembeli minta faktur', 'alasan_koreksi' => 'Catatan', 'posted_by' => 'penyusup', 'void_reason' => 'palsu']);
        $second = $this->service()->correct($this->user(), 'A', ['id_penjualan' => $id, 'catatan' => 'Faktur dikirim']);

        $this->assertSame('R01', $first['data']['revision_no']);
        $this->assertSame('R02', $second['data']['revision_no']);
        $stored = $this->all('penjualan')[0];
        $this->assertSame('Faktur dikirim', $stored['catatan']);
        $this->assertSame('POSTED', $stored['status_transaksi'], 'a correction cannot reopen a posted sale');
        $this->assertSame('kasir', $stored['posted_by']);
        $this->assertArrayNotHasKey('void_reason', $stored);
        $this->assertCount(2, $this->all('penjualan_koreksi'));
        $this->assertEquals(295000, $stored['total_tagihan']);
    }
}
