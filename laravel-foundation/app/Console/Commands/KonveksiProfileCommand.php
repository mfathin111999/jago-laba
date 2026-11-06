<?php

namespace App\Console\Commands;

use App\Database\KonveksiFieldProfiler;
use App\Services\Konveksi\KonveksiCollections;
use App\Services\Konveksi\KonveksiConnection;
use Illuminate\Console\Command;

/**
 * Read-only: looks at the real records of every Konveksi collection and reports, per field, the kinds of values seen,
 * the longest value, how often it is empty and a suggested column type. The base for choosing typed columns.
 */
final class KonveksiProfileCommand extends Command
{
    protected $signature = 'konveksi:profile
        {--only= : Comma-separated collection keys}
        {--json= : Also write the full profile to this JSON file}';

    protected $description = 'Profile the Konveksi records field by field and suggest column types (read-only)';

    public function handle(KonveksiConnection $konveksi, KonveksiCollections $collections): int
    {
        $only = $this->option('only') ? array_map('trim', explode(',', (string) $this->option('only'))) : null;
        $profile = [];

        $maps = $konveksi->connection()->table('system_table_map')->orderBy('id')->get(['api_key', 'sheet_name'])->all();
        foreach ($maps as $map) {
            $key = (string) $map->api_key;
            if (($only !== null && ! in_array($key, $only, true)) || ! $collections->available($key)) {
                continue;
            }
            $profile[$key] = KonveksiFieldProfiler::profile($collections->documents($key));
        }

        foreach ($profile as $key => $fields) {
            $rows = $fields['_rows'];
            unset($fields['_rows']);
            if ($rows === 0) {
                $this->line("<fg=gray>{$key}: kosong (0 baris)</>");

                continue;
            }
            $this->info("{$key} ({$rows} baris)");
            $this->table(['Field', 'Terisi', 'Jenis nilai', 'Maks', 'Saran', 'Contoh'], array_map(
                static fn (string $name, array $f): array => [$name, $f['filled'], implode(',', array_keys($f['kinds'])), $f['max_length'], $f['suggest'], implode(' | ', array_slice($f['samples'], 0, 2))],
                array_keys($fields),
                array_values($fields),
            ));
        }

        if ($this->option('json')) {
            file_put_contents((string) $this->option('json'), json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->line('Profil ditulis ke '.$this->option('json'));
        }

        return self::SUCCESS;
    }
}
