<?php

namespace App\Services\Konveksi;

use App\Exceptions\KonveksiTableMissing;
use App\Services\Konveksi\Storage\CollectionDriver;
use App\Services\Konveksi\Storage\JsonCollectionDriver;
use RuntimeException;

/**
 * The single door to Konveksi collection data. A collection key (api_key in system_table_map) resolves to a table,
 * and the table decides how rows are stored: a JSON document per row (jl_data_*) or typed columns (kv_*). Callers
 * always work with records as associative arrays, so moving a collection between layouts changes nothing for them.
 * Table names are checked against an allowlist pattern.
 */
final class KonveksiCollections
{
    private const TABLE_PATTERN = '/^(jl_data|kv)_[a-z0-9_]+$/';

    /** Tables already confirmed to exist. The key -> table lookup is not cached, so a switch to another layout applies at once, even in a long-running worker. */
    private array $present = [];

    public function __construct(
        private readonly KonveksiConnection $konveksi,
        private readonly TypedCollections $typed,
    ) {}

    /**
     * @return list<array{id: int|string, data: array<string, mixed>}>
     */
    public function rows(string $key, bool $lock = false): array
    {
        [$driver, $table] = $this->resolve($key);

        return $driver->rows($this->konveksi->connection(), $table, $lock);
    }

    /**
     * Records only, in display order.
     *
     * @return list<array<string, mixed>>
     */
    public function documents(string $key): array
    {
        return array_map(static fn (array $row): array => $row['data'], $this->rows($key));
    }

    public function count(string $key): int
    {
        [$driver, $table] = $this->resolve($key);

        return $driver->count($this->konveksi->connection(), $table);
    }

    /** Whether system_table_map has a row for the collection (the table itself may still be missing). */
    public function mapped(string $key): bool
    {
        $connection = $this->konveksi->connection();
        if ($connection->table('system_table_map')->where('api_key', $key)->exists()) {
            return true;
        }

        return in_array($key, ['hutangsupplier', 'hutang_supplier'], true)
            && $connection->table('system_table_map')->where('api_key', $key === 'hutangsupplier' ? 'hutang_supplier' : 'hutangsupplier')->exists();
    }

    /** Whether the collection is mapped to an existing table (some optional collections are not). */
    public function available(string $key): bool
    {
        try {
            $this->table($key);

            return true;
        } catch (RuntimeException|KonveksiTableMissing) {
            return false;
        }
    }

    /** @param array<string, mixed> $data */
    public function insert(string $key, array $data, int $order = 0): void
    {
        [$driver, $table] = $this->resolve($key);
        $driver->insert($this->konveksi->connection(), $table, $data, $order);
    }

    /** @param array<string, mixed> $data */
    public function update(string $key, int|string $rowId, array $data): void
    {
        [$driver, $table] = $this->resolve($key);
        $driver->update($this->konveksi->connection(), $table, $rowId, $data);
    }

    public function delete(string $key, int|string $rowId): void
    {
        [$driver, $table] = $this->resolve($key);
        $driver->delete($this->konveksi->connection(), $table, $rowId);
    }

    /**
     * Replaces the whole collection (a client sync). Entries that are not records are skipped; the row order is the
     * position in the given list.
     *
     * @param  array<int|string, mixed>  $records
     */
    public function replace(string $key, array $records): void
    {
        [$driver, $table] = $this->resolve($key);
        $connection = $this->konveksi->connection();
        $driver->deleteAll($connection, $table);
        foreach ($records as $index => $record) {
            if (is_array($record)) {
                $driver->insert($connection, $table, $record, is_int($index) ? $index : 0);
            }
        }
    }

    /**
     * Records of a table that was already resolved and checked from system_table_map by the caller.
     *
     * @return list<array<string, mixed>>
     */
    public function documentsOfTable(string $table): array
    {
        if (preg_match(self::TABLE_PATTERN, $table) !== 1) {
            throw new RuntimeException('Pemetaan tabel Konveksi tidak valid.');
        }
        $rows = $this->driver($table)->rows($this->konveksi->connection(), $table);

        return array_map(static fn (array $row): array => $row['data'], $rows);
    }

    /**
     * Every mapped collection as sheet name => records, in map order (for backups).
     *
     * @return iterable<string, list<array<string, mixed>>>
     */
    public function eachSheet(?array &$missing = null): iterable
    {
        $connection = $this->konveksi->connection();
        foreach ($connection->table('system_table_map')->orderBy('id')->get(['sheet_name', 'table_name']) as $map) {
            $table = (string) $map->table_name;
            if (preg_match(self::TABLE_PATTERN, $table) !== 1) {
                throw new RuntimeException('Pemetaan tabel Konveksi tidak valid.');
            }
            if (! $connection->getSchemaBuilder()->hasTable($table)) {
                $missing[] = $table;

                continue;
            }
            $rows = $this->driver($table)->rows($connection, $table);

            yield (string) $map->sheet_name => array_map(static fn (array $row): array => $row['data'], $rows);
        }
    }

    /** @return array{0: CollectionDriver, 1: string} */
    private function resolve(string $key): array
    {
        $table = $this->table($key);

        return [$this->driver($table), $table];
    }

    private function driver(string $table): CollectionDriver
    {
        return $this->typed->driverFor($table) ?? new JsonCollectionDriver;
    }

    private function table(string $key): string
    {
        $connection = $this->konveksi->connection();
        $table = (string) $connection->table('system_table_map')->where('api_key', $key)->value('table_name');
        // The snapshot importer maps the HutangSupplier sheet as hutang_supplier while purchases say hutangsupplier;
        // the legacy store treats them as one collection, so do the same.
        if ($table === '' && in_array($key, ['hutangsupplier', 'hutang_supplier'], true)) {
            $table = (string) $connection->table('system_table_map')->where('api_key', $key === 'hutangsupplier' ? 'hutang_supplier' : 'hutangsupplier')->value('table_name');
        }
        if ($table === '') {
            throw new RuntimeException("Tabel {$key} belum dipetakan.");
        }
        if (preg_match(self::TABLE_PATTERN, $table) !== 1) {
            throw new RuntimeException("Tabel {$key} tidak valid.");
        }
        if (! isset($this->present[$table])) {
            if (! $connection->getSchemaBuilder()->hasTable($table)) {
                // Mapped but absent is a broken installation, not a business error: it surfaces as a masked 500.
                throw KonveksiTableMissing::for($table);
            }
            $this->present[$table] = true;
        }

        return $table;
    }
}
