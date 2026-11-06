<?php

namespace App\Console\Commands;

use App\Database\KonveksiSchema;
use App\Services\Konveksi\KonveksiConnection;
use App\Services\Konveksi\TypedCollections;
use Illuminate\Console\Command;

/**
 * Read-only: compares the Konveksi database with the schema the migrations define and reports the differences.
 * Never changes anything, so it is safe on production. Exit code 1 when something required is missing.
 */
final class KonveksiSchemaCheckCommand extends Command
{
    protected $signature = 'konveksi:schema-check';

    protected $description = 'Report differences between the Konveksi database and the schema defined by the migrations (read-only)';

    public function handle(KonveksiConnection $konveksi): int
    {
        $connection = $konveksi->connection();
        $schema = $connection->getSchemaBuilder();
        $database = $connection->getDatabaseName();

        $problems = [];
        $warnings = [];

        $expected = [];
        foreach (KonveksiSchema::collectionTables() as $table) {
            $expected[$table] = KonveksiSchema::COLLECTION_COLUMNS;
        }
        foreach (KonveksiSchema::SUPPORT_COLUMNS as $table => $columns) {
            $expected[$table] = $columns;
        }
        foreach (app(TypedCollections::class)->definitions() as $definition) {
            $expected[$definition->table] = $definition->tableColumns();
        }

        foreach ($expected as $table => $columns) {
            if (! $schema->hasTable($table)) {
                $problems[] = "Tabel hilang: {$table}";

                continue;
            }
            $missing = array_diff($columns, $schema->getColumnListing($table));
            if ($missing !== []) {
                $problems[] = "Kolom hilang di {$table}: ".implode(', ', $missing);
            }
        }

        if ($schema->hasTable('system_table_map')) {
            $map = $connection->table('system_table_map')->pluck('table_name', 'api_key');
            foreach (KonveksiSchema::mappedCollections() as $table => [, $key]) {
                if (! isset($map[$key])) {
                    $problems[] = "system_table_map tidak memuat koleksi: {$key}";
                } elseif ($map[$key] !== $table && $map[$key] !== app(TypedCollections::class)->definition($key)?->table) {
                    $problems[] = "system_table_map: {$key} menunjuk {$map[$key]}, seharusnya {$table}";
                }
            }
        }

        // getTables() can list the tables of every database on the server; only this database counts.
        foreach ($schema->getTables() as $row) {
            $name = (string) $row['name'];
            $inThisDatabase = ! isset($row['schema']) || in_array($row['schema'], [null, '', $database, 'main'], true);
            if ($inThisDatabase && str_starts_with($name, 'jl_data_') && ! isset($expected[$name])) {
                $warnings[] = "Tabel koleksi tidak dikenal (tidak diubah): {$name}";
            }
        }

        $this->line(sprintf('Diperiksa: %d tabel koleksi, %d tabel pendukung.', count(KonveksiSchema::collectionTables()), count(KonveksiSchema::SUPPORT_COLUMNS)));
        foreach ($warnings as $warning) {
            $this->warn($warning);
        }
        foreach ($problems as $problem) {
            $this->error($problem);
        }

        if ($problems === []) {
            $this->info('Skema Konveksi sesuai dengan migrasi.');

            return self::SUCCESS;
        }

        $this->line('Jalankan `php artisan migrate` untuk membuat yang hilang (tabel yang sudah ada tidak diubah).');

        return self::FAILURE;
    }
}
