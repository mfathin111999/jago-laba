<?php

namespace App\Services\Konveksi\Storage;

use Illuminate\Database\Connection;

/** Level A layout: one row per record, the record as JSON in _data_json (the original spreadsheet layout). */
final class JsonCollectionDriver implements CollectionDriver
{
    public function rows(Connection $connection, string $table, bool $lock = false): array
    {
        $query = $connection->table($table)->orderBy('_row_order')->orderBy('_row_id');
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

    public function insert(Connection $connection, string $table, array $data, int $order = 0): void
    {
        $connection->table($table)->insert(['_row_order' => $order, '_data_json' => $this->encode($data)]);
    }

    public function update(Connection $connection, string $table, int|string $rowId, array $data): void
    {
        $connection->table($table)->where('_row_id', $rowId)->update(['_data_json' => $this->encode($data)]);
    }

    public function delete(Connection $connection, string $table, int|string $rowId): void
    {
        $connection->table($table)->where('_row_id', $rowId)->delete();
    }

    public function deleteAll(Connection $connection, string $table): void
    {
        $connection->table($table)->delete();
    }

    public function count(Connection $connection, string $table): int
    {
        return $connection->table($table)->count();
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
