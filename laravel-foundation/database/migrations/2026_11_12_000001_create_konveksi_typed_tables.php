<?php

use App\Services\Konveksi\KonveksiConnection;
use App\Services\Konveksi\Typed\KonveksiTypedMigrator;
use App\Services\Konveksi\TypedCollections;
use Illuminate\Database\Migrations\Migration;

/**
 * Creates the typed Konveksi tables (kv_<collection>) next to the JSON tables. They stay empty and unused until
 * `php artisan konveksi:typed migrate` copies a collection over and switches system_table_map to it.
 * Existing tables are never changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(KonveksiTypedMigrator::class)->createTables();
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Migrasi tabel bertipe Konveksi tidak bisa dibatalkan di luar local/testing.');
        }
        $schema = app(KonveksiConnection::class)->connection()->getSchemaBuilder();
        foreach (app(TypedCollections::class)->definitions() as $definition) {
            $schema->dropIfExists($definition->table);
        }
    }
};
