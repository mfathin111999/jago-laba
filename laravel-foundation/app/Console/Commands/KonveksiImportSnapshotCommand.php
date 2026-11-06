<?php

namespace App\Console\Commands;

use App\Database\KonveksiSnapshotImporter;
use App\Services\Konveksi\KonveksiConnection;
use Illuminate\Console\Command;
use Throwable;

/**
 * Loads the original spreadsheet snapshot into an empty Konveksi database (a fresh install or a staging copy).
 * It refuses to touch a table that already holds data unless --replace is given, and refuses --replace in
 * production unless --force is given as well.
 */
final class KonveksiImportSnapshotCommand extends Command
{
    protected $signature = 'konveksi:import-snapshot
        {--file= : Snapshot JSON (default: database/legacy/current_database_snapshot.json)}
        {--only= : Comma-separated collection keys to load, e.g. produk,bahan}
        {--replace : Replace the rows of the collections being loaded}
        {--force : Allow --replace in production}';

    protected $description = 'Load the Konveksi spreadsheet snapshot into the collection tables (run `php artisan migrate` first)';

    public function handle(KonveksiConnection $konveksi): int
    {
        $replace = (bool) $this->option('replace');
        if ($replace && app()->environment('production') && ! $this->option('force')) {
            $this->error('--replace menimpa data operasional; di production harus disertai --force.');

            return self::FAILURE;
        }

        $only = $this->option('only') ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('only'))))) : null;
        $file = (string) ($this->option('file') ?: $this->defaultSnapshot());

        try {
            $result = (new KonveksiSnapshotImporter($konveksi->connection()))->import($file, $replace, $only);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Sheet', 'Tabel', 'Sumber', 'Tabel (baris)', 'Status'], array_map(static fn (array $row): array => array_values($row), $result['sheets']));
        foreach ($result['skipped'] as $sheet) {
            $this->warn("Sheet tidak dikenal, dilewati: {$sheet}");
        }
        $failed = array_filter($result['sheets'], static fn (array $row): bool => $row['status'] !== 'PASS');
        $this->line(sprintf('%d sheet, %d baris dimuat.', count($result['sheets']), $result['rows']));

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /** The snapshot lives in database/legacy: next to the Laravel app when it is the project root, else one level up. */
    private function defaultSnapshot(): string
    {
        foreach ([base_path('database/legacy/current_database_snapshot.json'), dirname(base_path()).'/database/legacy/current_database_snapshot.json'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return base_path('database/legacy/current_database_snapshot.json');
    }
}
