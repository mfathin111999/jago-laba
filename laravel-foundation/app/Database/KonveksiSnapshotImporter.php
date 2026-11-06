<?php

namespace App\Database;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Loads the original spreadsheet data (database/legacy/current_database_snapshot.json: sheets of headers and rows)
 * into the Konveksi collection tables. Only the fixed columns are written: the record key, the row order and the
 * record as JSON. The tables and the system_table_map rows come from the migrations.
 *
 * The record key and the JSON encoding are the same as the legacy SnapshotImporter, so a database loaded here holds
 * exactly what the legacy tool would have loaded.
 */
final class KonveksiSnapshotImporter
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** Fields the legacy importer tried, in this order, to find a record's own id. */
    private const KEY_FIELDS = ['id', 'id_penjualan', 'id_mutasi', 'id_stok_item', 'id_bahan', 'id_produk', 'id_pegawai', 'id_supplier', 'id_dokumen', 'id_hutang', 'kode', 'kode_sku', 'kode_bahan', 'no_penjualan', 'no_faktur'];

    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * @param  list<string>|null  $only  collection keys to load (null = every sheet in the file)
     * @return array{sheets: list<array<string, mixed>>, skipped: list<string>, rows: int}
     */
    public function import(string $file, bool $replace, ?array $only = null): array
    {
        if (! is_file($file)) {
            throw new InvalidArgumentException('Snapshot tidak ditemukan: '.$file);
        }
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $sheets = is_array($data['sheets'] ?? null) ? $data['sheets'] : [];

        $targets = [];
        $skipped = [];
        foreach ($sheets as $sheet => $payload) {
            $table = $this->tableFor((string) $sheet);
            if ($table === null) {
                $skipped[] = (string) $sheet;

                continue;
            }
            [$key] = $table;
            if ($only !== null && ! in_array($key, $only, true)) {
                continue;
            }
            $targets[(string) $sheet] = [$key, 'jl_data_'.$key, $payload];
        }

        $this->assertReady(array_column($targets, 1), $replace);

        $results = [];
        $total = 0;
        foreach ($targets as $sheet => [$key, $table, $payload]) {
            $rows = array_values(is_array($payload['rows'] ?? null) ? $payload['rows'] : []);
            $headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];

            $this->connection->transaction(function () use ($sheet, $key, $table, $rows, $headers, $replace): void {
                if ($replace) {
                    $this->connection->table($table)->delete();
                }
                $now = now();
                foreach (array_chunk($rows, 200, true) as $chunk) {
                    $insert = [];
                    foreach ($chunk as $index => $row) {
                        $insert[] = [
                            '_record_key' => $this->recordKey((array) $row),
                            '_row_order' => $index + 1,
                            '_data_json' => json_encode($row, self::JSON_FLAGS),
                            '_created_at' => $now,
                            '_updated_at' => $now,
                        ];
                    }
                    $this->connection->table($table)->insert($insert);
                }

                $map = ['sheet_name' => $sheet, 'headers_json' => json_encode($headers, JSON_UNESCAPED_UNICODE), 'source_row_count' => count($rows), 'imported_row_count' => count($rows), 'checksum' => hash('sha256', json_encode($rows, self::JSON_FLAGS)), 'updated_at' => $now];
                // A collection already moved to a typed table keeps pointing at it; only a new map row names the JSON table.
                $exists = $this->connection->table('system_table_map')->where('api_key', $key)->exists();
                $this->connection->table('system_table_map')->updateOrInsert(['api_key' => $key], $exists ? $map : $map + ['table_name' => $table]);
            });

            $count = $this->connection->table($table)->count();
            $results[] = ['sheet' => $sheet, 'table' => $table, 'source' => count($rows), 'target' => $count, 'status' => $count === count($rows) ? 'PASS' : 'FAIL'];
            $total += $count;
        }

        return ['sheets' => $results, 'skipped' => $skipped, 'rows' => $total];
    }

    /** @return array{0: string}|null [collection key] for a sheet that has a table, null for an unknown sheet */
    private function tableFor(string $sheet): ?array
    {
        foreach (KonveksiSchema::registry()['collections'] as [$name, $key]) {
            if ($name === $sheet) {
                return [$key];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $tables
     */
    private function assertReady(array $tables, bool $replace): void
    {
        $schema = $this->connection->getSchemaBuilder();
        foreach ($tables as $table) {
            if (! $schema->hasTable($table)) {
                throw new RuntimeException("Tabel {$table} belum ada. Jalankan `php artisan migrate` lebih dulu.");
            }
        }
        if (! $schema->hasTable('system_table_map')) {
            throw new RuntimeException('Tabel system_table_map belum ada. Jalankan `php artisan migrate` lebih dulu.');
        }
        if ($replace) {
            return;
        }
        foreach ($tables as $table) {
            if ($this->connection->table($table)->exists()) {
                throw new RuntimeException("Tabel {$table} sudah berisi data. Impor menolak menimpanya; gunakan --replace bila memang itu tujuannya.");
            }
        }
    }

    /** @param array<string, mixed> $row */
    private function recordKey(array $row): string
    {
        foreach (self::KEY_FIELDS as $field) {
            if (isset($row[$field]) && $row[$field] !== '') {
                return substr((string) $row[$field], 0, 190);
            }
        }
        foreach ($row as $field => $value) {
            if (str_starts_with((string) $field, 'id_') && $value !== '' && $value !== null) {
                return substr((string) $value, 0, 190);
            }
        }

        return '';
    }
}
