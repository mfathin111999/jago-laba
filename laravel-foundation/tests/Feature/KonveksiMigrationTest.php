<?php

namespace Tests\Feature;

use App\Database\KonveksiSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class KonveksiMigrationTest extends TestCase
{
    private function migrate(): void
    {
        foreach (['2026_11_10_000001_create_konveksi_support_tables', '2026_11_10_000002_create_konveksi_collection_tables'] as $name) {
            (require database_path('migrations/'.$name.'.php'))->up();
        }
    }

    public function test_the_migrations_create_every_collection_and_support_table(): void
    {
        $this->migrate();

        foreach (KonveksiSchema::collectionTables() as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertSame([], array_diff(KonveksiSchema::COLLECTION_COLUMNS, Schema::getColumnListing($table)), $table);
        }
        foreach (KonveksiSchema::SUPPORT_COLUMNS as $table => $columns) {
            $this->assertEqualsCanonicalizing($columns, Schema::getColumnListing($table), $table);
        }
        $this->assertCount(39, KonveksiSchema::collectionTables());
    }

    public function test_the_collection_map_is_seeded_and_the_application_can_use_a_collection(): void
    {
        $this->migrate();

        $this->assertSame(38, DB::table('system_table_map')->count());
        $this->assertSame('jl_data_produk', DB::table('system_table_map')->where('api_key', 'produk')->value('table_name'));
        $this->assertSame('HutangSupplier', DB::table('system_table_map')->where('api_key', 'hutang_supplier')->value('sheet_name'));

        // The shape the Konveksi services read and write: a JSON document per row, ordered by _row_order/_row_id.
        DB::table('jl_data_produk')->insert(['_row_order' => 1, '_record_key' => 'PRD-1', '_data_json' => json_encode(['id' => 'PRD-1', 'nama' => 'Tas'])]);
        $row = DB::table('jl_data_produk')->first();
        $this->assertSame('Tas', json_decode($row->_data_json, true)['nama']);
        $this->assertNotNull($row->_created_at);
        $this->assertNotNull($row->_updated_at);
    }

    public function test_running_the_migrations_again_changes_nothing(): void
    {
        $this->migrate();
        DB::table('jl_data_bahan')->insert(['_row_order' => 1, '_data_json' => '{"id":"B1"}']);
        DB::table('system_table_map')->where('api_key', 'bahan')->update(['imported_row_count' => 1]);

        $this->migrate();

        $this->assertSame(1, DB::table('jl_data_bahan')->count(), 'existing rows survive');
        $this->assertSame(1, (int) DB::table('system_table_map')->where('api_key', 'bahan')->value('imported_row_count'), 'existing map rows are not overwritten');
        $this->assertSame(38, DB::table('system_table_map')->count());
    }

    public function test_an_existing_table_with_extra_legacy_columns_is_adopted_untouched(): void
    {
        // What the legacy installer built: the fixed columns plus a text column per spreadsheet header.
        Schema::create('jl_data_produk', function (Blueprint $table): void {
            $table->id('_row_id');
            $table->string('_record_key', 190)->nullable();
            $table->integer('_row_order')->default(0);
            $table->longText('_data_json');
            $table->dateTime('_created_at')->nullable();
            $table->dateTime('_updated_at')->nullable();
            $table->longText('kategori')->nullable();
        });
        DB::table('jl_data_produk')->insert(['_row_order' => 1, '_data_json' => '{"id":"PRD-1"}', 'kategori' => 'Ransel']);

        $this->migrate();

        $this->assertContains('kategori', Schema::getColumnListing('jl_data_produk'), 'the legacy column is kept');
        $this->assertSame('Ransel', DB::table('jl_data_produk')->value('kategori'));
        $this->assertTrue(Schema::hasTable('jl_data_bahan'), 'the missing tables are still created');
    }

    public function test_schema_check_passes_after_migrating_and_reports_what_is_missing(): void
    {
        $this->assertSame(1, Artisan::call('konveksi:schema-check'), 'an empty database fails the check');

        $this->migrate();
        $this->assertSame(0, Artisan::call('konveksi:schema-check'));
        $this->assertStringContainsString('sesuai dengan migrasi', Artisan::output());

        Schema::drop('jl_data_pegawai');
        DB::table('system_table_map')->where('api_key', 'supplier')->delete();
        Schema::create('jl_data_mystery', function (Blueprint $table): void {
            $table->id();
        });

        $this->assertSame(1, Artisan::call('konveksi:schema-check'));
        $output = Artisan::output();
        $this->assertStringContainsString('Tabel hilang: jl_data_pegawai', $output);
        $this->assertStringContainsString('system_table_map tidak memuat koleksi: supplier', $output);
        $this->assertStringContainsString('jl_data_mystery', $output);
    }

    public function test_rolling_back_is_refused_outside_local_and_testing(): void
    {
        $this->migrate();
        $migration = require database_path('migrations/2026_11_10_000002_create_konveksi_collection_tables.php');

        $this->app['env'] = 'production';
        $this->expectException(\RuntimeException::class);
        try {
            $migration->down();
        } finally {
            $this->assertTrue(Schema::hasTable('jl_data_produk'), 'nothing was dropped');
        }
    }
}
