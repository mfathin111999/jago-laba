<?php

namespace App\Services\Konveksi;

use RuntimeException;

/**
 * Row-level access to the Konveksi "collections": tables named in system_table_map whose rows are
 * a JSON document in _data_json. Table names are checked against the jl_data_ allowlist pattern.
 */
final class KonveksiCollections
{
    private const TABLE_PATTERN = '/^jl_data_[a-z0-9_]+$/';

    /** @var array<string, string> */
    private array $tables = [];

    public function __construct(private readonly KonveksiConnection $konveksi) {}

    /**
     * @return list<array{id: int|string, data: array<string, mixed>}>
     */
    public function rows(string $key, bool $lock = false): array
    {
        $query = $this->konveksi->connection()->table($this->table($key))->orderBy('_row_order')->orderBy('_row_id');
        if ($lock) {
            $query->lockForUpdate();
        }

        $rows = [];
        foreach ($query->get(['_row_id', '_data_json']) as $row) {
            $data = json_decode((string) $row->_data_json, true);
            if (is_array($data)) {
                $rows[] = ['id' => $row->_row_id, 'data' => $data];
            }
        }

        return $rows;
    }

    /** Whether the collection is mapped to an existing table (some optional collections are not). */
    public function available(string $key): bool
    {
        try {
            $this->table($key);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /** @param array<string, mixed> $data */
    public function insert(string $key, array $data): void
    {
        $this->konveksi->connection()->table($this->table($key))->insert(['_row_order' => 0, '_data_json' => $this->encode($data)]);
    }

    /** @param array<string, mixed> $data */
    public function update(string $key, int|string $rowId, array $data): void
    {
        $this->konveksi->connection()->table($this->table($key))->where('_row_id', $rowId)->update(['_data_json' => $this->encode($data)]);
    }

    public function delete(string $key, int|string $rowId): void
    {
        $this->konveksi->connection()->table($this->table($key))->where('_row_id', $rowId)->delete();
    }

    private function table(string $key): string
    {
        if (isset($this->tables[$key])) {
            return $this->tables[$key];
        }

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
        if (preg_match(self::TABLE_PATTERN, $table) !== 1 || ! $connection->getSchemaBuilder()->hasTable($table)) {
            throw new RuntimeException("Tabel {$key} tidak valid atau belum ada.");
        }

        return $this->tables[$key] = $table;
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
