<?php

namespace Tests\Feature;

use App\Database\KonveksiSchema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class KonveksiSnapshotImportTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['2026_11_10_000001_create_konveksi_support_tables', '2026_11_10_000002_create_konveksi_collection_tables'] as $name) {
            (require database_path('migrations/'.$name.'.php'))->up();
        }

        $this->file = sys_get_temp_dir().'/jl-snapshot-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($this->file, json_encode(['metadata' => ['source' => 'test'], 'sheets' => [
            'Produk' => ['headers' => ['id', 'nama'], 'rows' => [['id' => 'PRD-1', 'nama' => 'Tas Ransel'], ['id' => 'PRD-2', 'nama' => 'Tas "Mono" é/ü']]],
            'Pegawai' => ['headers' => ['id_pegawai', 'nama'], 'rows' => [['id_pegawai' => 'PEG-9', 'nama' => 'Ani']]],
            'Kasbon' => ['headers' => ['catatan'], 'rows' => [['catatan' => 'tanpa id']]],
            'Sheet_Aneh' => ['headers' => ['x'], 'rows' => [['x' => 1]]],
        ]]));
    }

    protected function tearDown(): void
    {
        File::delete($this->file);
        parent::tearDown();
    }

    public function test_rows_are_loaded_as_json_with_the_same_record_key_and_order_as_the_legacy_importer(): void
    {
        $this->assertSame(0, Artisan::call('konveksi:import-snapshot', ['--file' => $this->file]));

        $rows = DB::table('jl_data_produk')->orderBy('_row_order')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['PRD-1', 'PRD-2'], $rows->pluck('_record_key')->all());
        $this->assertSame([1, 2], $rows->pluck('_row_order')->map(fn ($v) => (int) $v)->all());
        $this->assertSame('{"id":"PRD-2","nama":"Tas \"Mono\" é/ü"}', $rows[1]->_data_json, 'unicode and slashes are not escaped, as before');

        $this->assertSame('PEG-9', DB::table('jl_data_pegawai')->value('_record_key'), 'falls back to the first id_* field');
        $this->assertSame('', DB::table('jl_data_kasbon')->value('_record_key'), 'no id means an empty key');
        $this->assertStringContainsString('Sheet tidak dikenal, dilewati: Sheet_Aneh', Artisan::output());
    }

    public function test_the_collection_map_records_the_counts_and_a_checksum(): void
    {
        Artisan::call('konveksi:import-snapshot', ['--file' => $this->file]);

        $map = DB::table('system_table_map')->where('api_key', 'produk')->first();
        $this->assertSame(2, (int) $map->source_row_count);
        $this->assertSame(2, (int) $map->imported_row_count);
        $this->assertSame('["id","nama"]', $map->headers_json);
        $this->assertSame(64, strlen((string) $map->checksum));
        $this->assertSame(32, DB::table('system_table_map')->count(), 'no map row is added or lost');
    }

    public function test_a_table_that_already_holds_data_is_never_overwritten_without_replace(): void
    {
        DB::table('jl_data_produk')->insert(['_row_order' => 1, '_data_json' => '{"id":"KEEP"}']);

        $this->assertSame(1, Artisan::call('konveksi:import-snapshot', ['--file' => $this->file]));
        $this->assertStringContainsString('sudah berisi data', Artisan::output());
        $this->assertSame(['{"id":"KEEP"}'], DB::table('jl_data_produk')->pluck('_data_json')->all());
        $this->assertSame(0, DB::table('jl_data_pegawai')->count(), 'nothing was loaded at all');

        $this->assertSame(0, Artisan::call('konveksi:import-snapshot', ['--file' => $this->file, '--replace' => true]));
        $this->assertSame(2, DB::table('jl_data_produk')->count());
    }

    public function test_replace_needs_force_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->assertSame(1, Artisan::call('konveksi:import-snapshot', ['--file' => $this->file, '--replace' => true]));
        $this->assertStringContainsString('--force', Artisan::output());
        $this->assertSame(0, DB::table('jl_data_produk')->count());

        $this->assertSame(0, Artisan::call('konveksi:import-snapshot', ['--file' => $this->file, '--replace' => true, '--force' => true]));
        $this->assertSame(2, DB::table('jl_data_produk')->count());
    }

    public function test_only_loads_the_listed_collections(): void
    {
        $this->assertSame(0, Artisan::call('konveksi:import-snapshot', ['--file' => $this->file, '--only' => 'pegawai']));

        $this->assertSame(1, DB::table('jl_data_pegawai')->count());
        $this->assertSame(0, DB::table('jl_data_produk')->count());
    }

    public function test_it_asks_for_migrate_when_the_tables_are_missing(): void
    {
        DB::statement('DROP TABLE jl_data_produk');

        $this->assertSame(1, Artisan::call('konveksi:import-snapshot', ['--file' => $this->file]));
        $this->assertStringContainsString('php artisan migrate', Artisan::output());
    }

    public function test_the_real_snapshot_loads_completely(): void
    {
        $this->assertSame(0, Artisan::call('konveksi:import-snapshot'));

        $this->assertSame(226, (int) collect(KonveksiSchemaHelper::tables())->sum(fn (string $table) => DB::table($table)->count()));
        $this->assertStringNotContainsString('FAIL', Artisan::output());
        $this->assertSame(31, DB::table('jl_data_master_stok')->count());
        $this->assertSame(17, DB::table('jl_data_produk')->count());
    }
}

/** The collection tables a complete installation has. */
final class KonveksiSchemaHelper
{
    /** @return list<string> */
    public static function tables(): array
    {
        return KonveksiSchema::collectionTables();
    }
}
