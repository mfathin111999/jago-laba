<?php

namespace App\Services\Konveksi\Typed;

use App\Services\Konveksi\Storage\CollectionDriver;
use Illuminate\Database\Connection;

/** Level B layout: typed columns per field (see TypedDefinition), same record-in/record-out contract as the JSON layout. */
final class TypedCollectionDriver implements CollectionDriver
{
    private readonly TypedRowMapper $mapper;

    public function __construct(TypedDefinition $definition)
    {
        $this->mapper = new TypedRowMapper($definition);
    }

    public function rows(Connection $connection, string $table, bool $lock = false): array
    {
        $query = $connection->table($table)->orderBy('_row_order')->orderBy('_row_id');
        if ($lock) {
            $query->lockForUpdate();
        }

        $rows = [];
        foreach ($query->get() as $row) {
            $rows[] = ['id' => $row->_row_id, 'data' => $this->mapper->toRecord($row)];
        }

        return $rows;
    }

    public function insert(Connection $connection, string $table, array $data, int $order = 0): void
    {
        $connection->table($table)->insert($this->mapper->toRow($data) + ['_row_order' => $order]);
    }

    public function update(Connection $connection, string $table, int|string $rowId, array $data): void
    {
        $connection->table($table)->where('_row_id', $rowId)->update($this->mapper->toRow($data));
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

    public function mapper(): TypedRowMapper
    {
        return $this->mapper;
    }
}
