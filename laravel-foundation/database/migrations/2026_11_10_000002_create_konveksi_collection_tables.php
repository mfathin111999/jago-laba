<?php

use App\Database\KonveksiSchema;
use App\Services\Konveksi\KonveksiConnection;
use Illuminate\Database\Migrations\Migration;

/**
 * The jl_data_* collection tables (one per spreadsheet sheet of the original Google Sheets app) and the
 * system_table_map rows that map collection key -> table. Existing tables and map rows are kept as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = app(KonveksiConnection::class)->connection();
        $schema = new KonveksiSchema();

        $schema->createCollectionTables($connection->getSchemaBuilder());
        $schema->seedTableMap($connection);
    }

    public function down(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('Rollback tabel Konveksi dinonaktifkan di luar local/testing: data operasional bisa hilang.');
        }

        $schema = app(KonveksiConnection::class)->connection()->getSchemaBuilder();
        foreach (array_reverse(KonveksiSchema::collectionTables()) as $table) {
            $schema->dropIfExists($table);
        }
    }
};
