<?php

use App\Database\KonveksiSchema;
use App\Services\Konveksi\KonveksiConnection;
use Illuminate\Database\Migrations\Migration;

/**
 * Support tables of the Konveksi database (settings, collection map, audit, backups, documents, idempotency, request
 * receipts). They go to the database Konveksi lives in (KonveksiConnection: its own database when isolated, otherwise
 * the default one). A table that already exists, for example one the legacy installer built, is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new KonveksiSchema)->createSupportTables(app(KonveksiConnection::class)->connection()->getSchemaBuilder());
    }

    public function down(): void
    {
        // Operational data lives in these tables; never drop them outside a disposable environment.
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('Rollback tabel Konveksi dinonaktifkan di luar local/testing: data operasional bisa hilang.');
        }

        $schema = app(KonveksiConnection::class)->connection()->getSchemaBuilder();
        foreach (array_reverse(array_keys(KonveksiSchema::SUPPORT_COLUMNS)) as $table) {
            $schema->dropIfExists($table);
        }
    }
};
