<?php

namespace App\Console\Commands;

use App\Database\MarketplaceSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Read-only: reports Marketplace/system tables or columns that the golden schema defines but the database lacks. */
final class MarketplaceSchemaCheckCommand extends Command
{
    protected $signature = 'marketplace:schema-check';

    protected $description = 'Report differences between the database and the Marketplace schema defined by the migrations (read-only)';

    public function handle(): int
    {
        $schema = DB::connection()->getSchemaBuilder();
        $problems = [];
        $expected = MarketplaceSchema::columns();

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

        $this->line(sprintf('Diperiksa: %d tabel Marketplace/sistem.', count($expected)));
        foreach ($problems as $problem) {
            $this->error($problem);
        }
        if ($problems === []) {
            $this->info('Skema Marketplace sesuai dengan migrasi.');

            return self::SUCCESS;
        }
        $this->line('Jalankan `php artisan migrate` untuk membuat tabel yang hilang (tabel yang ada tidak diubah).');

        return self::FAILURE;
    }
}
