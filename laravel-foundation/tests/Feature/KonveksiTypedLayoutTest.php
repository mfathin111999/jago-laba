<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use App\Services\Konveksi\KonveksiCollections;
use App\Services\Konveksi\KonveksiPurchaseWriteService;
use App\Services\Konveksi\Storage\JsonCollectionDriver;
use App\Services\Konveksi\Typed\KonveksiTypedMigrator;
use App\Services\Konveksi\Typed\TypedColumn;
use App\Services\Konveksi\Typed\TypedDefinition;
use App\Services\Konveksi\Typed\TypedRowMapper;
use App\Services\Konveksi\TypedCollections;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class KonveksiTypedLayoutTest extends TestCase
{
    private function install(): void
    {
        foreach (['2026_11_10_000001_create_konveksi_support_tables', '2026_11_10_000002_create_konveksi_collection_tables', '2026_11_12_000001_create_konveksi_typed_tables'] as $name) {
            (require database_path('migrations/'.$name.'.php'))->up();
        }
        $this->assertSame(0, Artisan::call('konveksi:import-snapshot'));
    }

    // ---- column conversions ----------------------------------------------------------------------------------

    public function test_columns_convert_values_and_return_null_when_they_do_not_fit(): void
    {
        $date = TypedColumn::parse('tanggal', 'date');
        $this->assertSame('2026-09-18', $date->toColumn(46284), 'a spreadsheet serial becomes a real date');
        $this->assertSame('2026-09-18', $date->toColumn('2026-09-18'));
        $this->assertSame('2026-09-19', $date->toColumn('2026-09-19T12:24:24.300Z'));
        $this->assertNull($date->toColumn('besok'));

        $money = TypedColumn::parse('nominal', 'decimal:18,2');
        $this->assertSame('340000.00', $money->toColumn(340000));
        $this->assertSame('12.50', $money->toColumn('12.5'));
        $this->assertNull($money->toColumn('Rp 1.000'));
        $this->assertSame(340000, $money->fromColumn('340000.00'));
        $this->assertSame(12.5, $money->fromColumn('12.50'));

        $this->assertNull(TypedColumn::parse('id', 'bigint')->toColumn('ABC'));
        $this->assertSame(1789821912500, TypedColumn::parse('id', 'bigint')->toColumn('1789821912500'));
        $this->assertNull(TypedColumn::parse('kode', 'string:5')->toColumn('terlalu panjang'));
        $this->assertSame('', TypedColumn::parse('kode', 'string:5')->fromColumn(null));
    }

    // ---- lossless round trip ---------------------------------------------------------------------------------

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function records(): array
    {
        return [
            'clean' => [['id' => 7, 'nama' => 'Kas', 'nominal' => 1500, 'tanggal' => '2026-11-04', 'catatan' => 'ok']],
            'serial date and number kept as text' => [['id' => '1789821912500', 'tanggal' => 46284, 'nominal' => '5000', 'nama' => 123]],
            'null and empty' => [['id' => null, 'nama' => '', 'nominal' => null, 'tanggal' => '']],
            'missing fields stay missing' => [['id' => 1]],
            'unknown fields are kept' => [['id' => 2, 'ekstra' => ['a' => 1, 'b' => [true, null]], 'lain' => 'x']],
            'value that does not fit' => [['id' => 'ABC', 'nominal' => 'Rp 1.000', 'tanggal' => 'besok', 'nama' => str_repeat('x', 300)]],
            'float with zero fraction' => [['id' => 3, 'nominal' => 10.0]],
            'unicode' => [['id' => 4, 'nama' => 'Tas "Mono" é/ü ✓']],
        ];
    }

    /** @param array<string, mixed> $record */
    #[\PHPUnit\Framework\Attributes\DataProvider('records')]
    public function test_a_record_comes_back_exactly_as_written(array $record): void
    {
        $definition = TypedDefinition::fromArray('uji', ['columns' => ['id' => 'bigint', 'nama' => 'string:60', 'nominal' => 'decimal:18,2', 'tanggal' => 'date', 'catatan' => 'text']]);
        $mapper = new TypedRowMapper($definition);

        $row = $mapper->toRow($record);
        $stored = json_decode(json_encode($row), true); // what the database hands back

        // The reference is the JSON layout: a record stored as JSON text comes back as json_decode(json_encode(record)).
        $viaJson = json_decode(json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
        $this->assertSame($this->canonical($viaJson), $this->canonical($mapper->toRecord($stored)));
    }

    // ---- real data, whole pipeline ---------------------------------------------------------------------------

    public function test_every_typed_collection_of_the_real_snapshot_is_identical_after_the_move(): void
    {
        $this->install();
        $before = [];
        $keys = array_keys(app(TypedCollections::class)->definitions());
        foreach ($keys as $key) {
            $before[$key] = app(KonveksiCollections::class)->documents($key);
        }

        $this->assertSame(0, Artisan::call('konveksi:typed', ['action' => 'migrate', '--all' => true]), Artisan::output());

        $collections = new KonveksiCollections(app(\App\Services\Konveksi\KonveksiConnection::class), app(TypedCollections::class));
        foreach ($keys as $key) {
            $this->assertSame('kv_'.$key, DB::table('system_table_map')->where('api_key', $key)->value('table_name'), $key);
            $this->assertSame($this->canonical($before[$key]), $this->canonical($collections->documents($key)), $key);
        }
        $this->assertGreaterThanOrEqual(20, count($keys));
    }

    public function test_the_typed_columns_hold_real_types(): void
    {
        $this->install();
        Artisan::call('konveksi:typed', ['action' => 'migrate', 'keys' => ['keuangan']]);

        $row = DB::table('kv_keuangan')->where('_row_id', 1)->first();
        $this->assertSame('2026-09-18', substr((string) $row->tanggal, 0, 10), 'serial 46284 is stored as a date');
        $this->assertEquals(340000, $row->nominal);
        $this->assertGreaterThan(0, DB::table('kv_keuangan')->whereDate('tanggal', '2026-09-18')->count(), 'dates can be queried');
        $this->assertGreaterThan(0, (int) DB::table('kv_keuangan')->sum('nominal'));
    }

    public function test_writes_go_through_the_gateway_on_the_typed_layout_and_a_rollback_keeps_them(): void
    {
        $this->install();
        Artisan::call('konveksi:typed', ['action' => 'migrate', 'keys' => ['rekening']]);
        $collections = app(KonveksiCollections::class);

        $collections->insert('rekening', ['id' => 99, 'kode' => 'BCA', 'nama' => 'BCA Utama', 'saldo_awal' => 1000, 'baru' => 'ya']);
        $rows = $collections->rows('rekening');
        $created = collect($rows)->firstWhere('data.id', 99);
        $collections->update('rekening', $created['id'], ['id' => 99, 'kode' => 'BCA', 'nama' => 'BCA Diubah', 'saldo_awal' => 2500.5, 'baru' => 'ya']);
        $first = collect($rows)->firstWhere("data.kode", "KS002SB");
        $collections->delete('rekening', $first['id']);

        $this->assertSame('BCA Diubah', collect($collections->documents('rekening'))->firstWhere('id', 99)['nama']);
        $this->assertSame(2500.5, collect($collections->documents('rekening'))->firstWhere('id', 99)['saldo_awal']);
        $this->assertSame(3, $collections->count('rekening'), '3 original + 1 new - 1 deleted');

        $this->assertSame(0, Artisan::call('konveksi:typed', ['action' => 'rollback', 'keys' => ['rekening']]));
        $this->assertSame('jl_data_rekening', DB::table('system_table_map')->where('api_key', 'rekening')->value('table_name'));
        $json = (new JsonCollectionDriver)->rows(DB::connection(), 'jl_data_rekening');
        $this->assertSame('BCA Diubah', collect($json)->firstWhere('data.id', 99)['data']['nama']);
        $this->assertCount(3, $json);
    }

    public function test_a_service_behaves_the_same_on_the_typed_layout(): void
    {
        $this->install();
        $service = app(KonveksiPurchaseWriteService::class);
        $user = new LegacyUser(['id' => 'u1', 'username' => 'gudang', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN']);
        $payload = ['id_bahan' => 'BHN-UJI', 'nama_bahan' => 'Kain Uji', 'kode_bahan' => 'KU-1', 'stok_awal' => 12, 'harga_beli' => 40000, 'isi_satuan_beli' => 4];

        $onJson = $service->saveMaterial($user, 'A', $payload);
        DB::table('jl_data_bahan')->where('_data_json', 'like', '%BHN-UJI%')->delete();
        DB::table('jl_data_mutasi_stok')->where('_data_json', 'like', '%BHN-UJI%')->delete();
        DB::table('jl_data_bahan_hpp')->delete();

        Artisan::call('konveksi:typed', ['action' => 'migrate', 'keys' => ['bahan', 'mutasi_stok'], '--replace' => true]);
        $onTyped = $service->saveMaterial($user, 'A', $payload);

        foreach (['updated_at', 'created_at'] as $volatile) {
            unset($onJson['material'][$volatile], $onTyped['material'][$volatile], $onJson['bahan_hpp'][$volatile], $onTyped['bahan_hpp'][$volatile]);
        }
        unset($onJson['mutasi'], $onTyped['mutasi'], $onJson['data'], $onTyped['data']);
        $this->assertSame($this->canonical($onJson), $this->canonical($onTyped));
        $this->assertTrue(collect(app(KonveksiCollections::class)->documents('bahan'))->contains('id_bahan', 'BHN-UJI'));
        $this->assertTrue(collect(app(KonveksiCollections::class)->documents('mutasi_stok'))->contains('id_bahan', 'BHN-UJI'));
    }

    public function test_posting_a_purchase_and_a_sale_stores_the_same_records_on_every_typed_table(): void
    {
        $this->install();
        $collections = app(KonveksiCollections::class);
        $user = new LegacyUser(['id' => 'u1', 'username' => 'kasir', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN']);
        $written = ['pembelian', 'pembelian_item', 'bahan', 'bahan_hpp', 'mutasi_stok', 'keuangan', 'hutang_supplier', 'penjualan', 'penjualan_item', 'master_stok', 'produk'];
        $seed = function () use ($collections, $written): void {
            foreach ($written as $key) {
                $collections->replace($key, []);
            }
            $collections->replace('bahan', [
                ['id_bahan' => 'B1', 'id' => 'B1', 'kode_bahan' => 'KAIN-1', 'nama_bahan' => 'Kain Katun', 'stok' => 10, 'harga_beli' => 40000],
                ['id_bahan' => 'B2', 'id' => 'B2', 'kode_bahan' => 'BTN-1', 'nama_bahan' => 'Kancing', 'stok' => 100, 'harga_beli' => 100],
            ]);
            $collections->replace('bahan_hpp', [['id_bahan_hpp' => 'HPP-B1', 'id_bahan' => 'B1', 'harga_aktif' => 4000]]);
            $collections->replace('produk', [['id' => 'P1', 'id_stok_item' => 'STK-1', 'kode' => 'KMJ-01', 'nama' => 'Kemeja', 'stok' => 10, 'hpp_produk' => 50000]]);
            $collections->replace('master_stok', [['id_stok_item' => 'STK-1', 'id_produk' => 'P1', 'kode_sku' => 'KMJ-01', 'nama_produk' => 'Kemeja', 'stok_akhir' => 10, 'stok' => 10, 'keluar_penjualan' => 0, 'stok_minimum' => 3]]);
        };
        $flow = function () use ($collections, $user, $written): array {
            app(KonveksiPurchaseWriteService::class)->saveInvoice($user, 'A', [
                'id_transaksi' => 'PBL-1', 'no_faktur_supplier' => 'INV-001', 'id_supplier' => 'SUP-1', 'nama_supplier' => 'Toko Kain', 'tanggal' => '2026-11-04',
                'id_rekening' => 'KAS-1', 'nominal_bayar' => 500000, 'jatuh_tempo' => '2026-12-04',
                'items' => [
                    ['id_bahan' => 'B1', 'qty' => 5, 'conversion_factor' => 40, 'harga_satuan' => 200000, 'subtotal' => 1000000],
                    ['id_bahan' => 'B2', 'qty' => 2, 'qty_satuan_pakai' => 500, 'harga_satuan' => 50000, 'subtotal' => 100000],
                ],
            ]);
            app(\App\Services\Konveksi\KonveksiSalesWriteService::class)->post($user, 'A', [
                'tanggal' => '2026-11-04', 'id_rekening' => 'KAS-1', 'diskon_transaksi' => 0, 'ongkir' => 5000, 'nominal_dibayar' => 300000,
                'items' => [['kode_sku' => 'KMJ-01', 'nama_produk' => 'Kemeja', 'qty' => 3, 'harga_jual' => 100000, 'diskon_item' => 10000]],
            ]);
            $out = [];
            foreach ($written as $key) {
                $out[$key] = $this->masked($collections->documents($key));
            }

            return $out;
        };

        $seed();
        $onJson = $flow();
        $this->assertNotEmpty($onJson['penjualan'], 'the flow must have written something to compare');

        $seed();
        $this->assertSame(0, Artisan::call('konveksi:typed', ['action' => 'migrate', '--all' => true, '--replace' => true]), Artisan::output());
        foreach (['penjualan', 'penjualan_item', 'pembelian', 'pembelian_item', 'bahan_hpp'] as $key) {
            $this->assertStringStartsWith('kv_', DB::table('system_table_map')->where('api_key', $key)->value('table_name'), $key.' must be typed now');
        }
        $onTyped = $flow();

        $this->assertSame($this->canonical($onJson), $this->canonical($onTyped));
        $this->assertGreaterThan(0, DB::table('kv_penjualan')->count());
        $this->assertGreaterThan(0, DB::table('kv_pembelian_item')->count());
    }

    /** Replaces what changes on every run (generated ids, clock values) so two runs can be compared. */
    private function masked(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->masked($item), $value);
        }
        if (is_string($value)) {
            $value = preg_replace(['/\d{8}-[0-9A-F]{8}/', '/\d{4}-\d{2}-\d{2}T[\d:.+Z-]+/', '/\d{8}-\d{6}/', '/^\d{2}:\d{2}:\d{2}$/', '/[0-9a-f]{64}/'], ['<id>', '<ts>', '<no>', '<time>', '<hash>'], $value);
        }

        return $value;
    }

    public function test_a_failed_verification_never_switches_the_collection(): void
    {
        $this->install();
        $migrator = app(KonveksiTypedMigrator::class);
        $migrator->backfill('rekening');
        DB::table('kv_rekening')->where('_row_id', 1)->update(['nama' => 'DIUBAH DIAM-DIAM']);

        $this->expectException(\RuntimeException::class);
        try {
            $migrator->switchToTyped('rekening');
        } finally {
            $this->assertSame('jl_data_rekening', DB::table('system_table_map')->where('api_key', 'rekening')->value('table_name'));
        }
    }

    public function test_a_typed_table_with_rows_is_not_overwritten_without_replace(): void
    {
        $this->install();
        app(KonveksiTypedMigrator::class)->backfill('rekening');

        $this->assertSame(1, Artisan::call('konveksi:typed', ['action' => 'migrate', 'keys' => ['rekening']]));
        $this->assertStringContainsString('--replace', Artisan::output());
    }

    public function test_the_schema_check_covers_the_typed_tables(): void
    {
        $this->install();
        $this->assertSame(0, Artisan::call('konveksi:schema-check'), Artisan::output());

        Artisan::call('konveksi:typed', ['action' => 'migrate', '--all' => true]);
        $this->assertSame(0, Artisan::call('konveksi:schema-check'), 'a collection mapped to its typed table is correct: '.Artisan::output());

        \Illuminate\Support\Facades\Schema::drop('kv_produk');
        $this->assertSame(1, Artisan::call('konveksi:schema-check'));
        $this->assertStringContainsString('Tabel hilang: kv_produk', Artisan::output());
    }

    /** Same keys, values and types regardless of key order. */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(fn ($item) => $this->canonical($item), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
