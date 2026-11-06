<?php

namespace App\Services\Konveksi\Typed;

use App\Database\KonveksiSchema;
use App\Services\Konveksi\KonveksiConnection;
use App\Services\Konveksi\Storage\JsonCollectionDriver;
use App\Services\Konveksi\TypedCollections;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Moves one collection between the JSON layout (jl_data_<key>) and the typed layout (kv_<key>).
 *
 *  - backfill copies every row with its row id and order, then proves the copy: each record read back through the
 *    typed driver must be identical (same keys, same values, same types) to the JSON record;
 *  - switch points system_table_map at the typed table (the JSON table stays as an archive);
 *  - rollback first copies the typed rows back into the JSON table, so writes made while typed are not lost, then
 *    points the map back.
 */
final class KonveksiTypedMigrator
{
    public function __construct(
        private readonly KonveksiConnection $konveksi,
        private readonly TypedCollections $typed,
    ) {}

    /** Creates the typed tables that do not exist yet. @return list<string> created */
    public function createTables(?Connection $connection = null): array
    {
        $connection ??= $this->konveksi->connection();
        $schema = $connection->getSchemaBuilder();
        $created = [];
        foreach ($this->typed->definitions() as $definition) {
            if (! $schema->hasTable($definition->table)) {
                $schema->create($definition->table, $definition->blueprint(...));
                $created[] = $definition->table;
            }
        }

        return $created;
    }

    /** @return array{key: string, layout: string, table: string, rows: int|null} */
    public function status(string $key): array
    {
        $table = $this->currentTable($key);
        $definition = $this->typed->definition($key);

        return [
            'key' => $key,
            'layout' => $definition !== null && $table === $definition->table ? 'typed' : 'json',
            'table' => $table,
            'rows' => $this->exists($table) ? $this->konveksi->connection()->table($table)->count() : null,
        ];
    }

    /**
     * Copies the JSON rows into the typed table and verifies them.
     *
     * @return array{rows: int, with_originals: int}
     */
    public function backfill(string $key, bool $replace = false): array
    {
        $definition = $this->definitionOrFail($key);
        $connection = $this->konveksi->connection();
        $source = $this->jsonTable($key);
        $this->createTables();

        if (! $replace && $connection->table($definition->table)->exists()) {
            throw new RuntimeException("Tabel {$definition->table} sudah berisi data; gunakan --replace untuk menimpanya.");
        }

        $mapper = new TypedRowMapper($definition);
        $withOriginals = 0;
        $connection->transaction(function () use ($connection, $definition, $source, $mapper, &$withOriginals): void {
            $connection->table($definition->table)->delete();
            foreach ($connection->table($source)->orderBy('_row_order')->orderBy('_row_id')->get() as $row) {
                $record = json_decode((string) $row->_data_json, true);
                if (! is_array($record)) {
                    continue;
                }
                $typed = $mapper->toRow($record);
                if (str_contains((string) $typed['_extra_json'], '"o"')) {
                    $withOriginals++;
                }
                $connection->table($definition->table)->insert($typed + array_filter([
                    '_row_id' => $row->_row_id,
                    '_row_order' => $row->_row_order,
                    '_created_at' => $row->_created_at ?? null,
                    '_updated_at' => $row->_updated_at ?? null,
                ], static fn ($value) => $value !== null));
            }
        });

        $count = $this->verify($key);

        return ['rows' => $count, 'with_originals' => $withOriginals];
    }

    /** Proves the typed table holds exactly the records of the JSON table. @return int number of records compared */
    public function verify(string $key): int
    {
        $definition = $this->definitionOrFail($key);
        $connection = $this->konveksi->connection();
        $json = (new JsonCollectionDriver)->rows($connection, $this->jsonTable($key));
        $typed = (new TypedCollectionDriver($definition))->rows($connection, $definition->table);

        if (count($json) !== count($typed)) {
            throw new RuntimeException(sprintf('Rekonsiliasi %s gagal: %d baris JSON, %d baris bertipe.', $key, count($json), count($typed)));
        }
        foreach ($json as $index => $row) {
            if ($row['id'] != $typed[$index]['id'] || $this->canonical($row['data']) !== $this->canonical($typed[$index]['data'])) {
                throw new RuntimeException(sprintf('Rekonsiliasi %s gagal pada baris %s: data bertipe berbeda dari JSON.', $key, (string) $row['id']));
            }
        }

        return count($json);
    }

    /** Points the collection at the typed table (after a fresh verification). */
    public function switchToTyped(string $key): void
    {
        $definition = $this->definitionOrFail($key);
        $this->verify($key);
        $this->point($key, $definition->table);
    }

    /** Copies the typed rows back into the JSON table and points the collection at it again. */
    public function rollbackToJson(string $key): void
    {
        $definition = $this->definitionOrFail($key);
        $connection = $this->konveksi->connection();
        $json = $this->jsonTable($key);
        $mapper = new TypedRowMapper($definition);

        $connection->transaction(function () use ($connection, $definition, $json, $mapper, $key): void {
            $connection->table($json)->delete();
            foreach ($connection->table($definition->table)->orderBy('_row_order')->orderBy('_row_id')->get() as $row) {
                $connection->table($json)->insert([
                    '_row_id' => $row->_row_id,
                    '_row_order' => $row->_row_order,
                    '_data_json' => json_encode($mapper->toRecord($row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ] + array_filter(['_created_at' => $row->_created_at, '_updated_at' => $row->_updated_at], static fn ($value) => $value !== null));
            }
            $this->point($key, $json);
        });
    }

    private function point(string $key, string $table): void
    {
        $this->konveksi->connection()->table('system_table_map')->where('api_key', $key)->update(['table_name' => $table]);
    }

    private function currentTable(string $key): string
    {
        return (string) $this->konveksi->connection()->table('system_table_map')->where('api_key', $key)->value('table_name');
    }

    /** The JSON table of a collection, whichever layout is active now. */
    private function jsonTable(string $key): string
    {
        foreach (KonveksiSchema::mappedCollections() as $table => [, $collection]) {
            if ($collection === $key) {
                return $table;
            }
        }

        throw new RuntimeException("Koleksi {$key} tidak memiliki tabel JSON.");
    }

    private function definitionOrFail(string $key): TypedDefinition
    {
        return $this->typed->definition($key) ?? throw new RuntimeException("Koleksi {$key} belum punya definisi bertipe.");
    }

    private function exists(string $table): bool
    {
        return $table !== '' && $this->konveksi->connection()->getSchemaBuilder()->hasTable($table);
    }

    /** Same keys, values and types regardless of key order. */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(fn ($item) => $this->canonical($item), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
