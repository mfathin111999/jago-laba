<?php

namespace App\Services\Konveksi\Storage;

use Illuminate\Database\Connection;

/**
 * How one collection's rows are stored. The application always sees a row as {id, data}, where data is the record
 * as the client knows it (an associative array); drivers translate to and from the table layout.
 */
interface CollectionDriver
{
    /** @return list<array{id: int|string, data: array<string, mixed>}> in display order (row order, then id) */
    public function rows(Connection $connection, string $table, bool $lock = false): array;

    /** @param array<string, mixed> $data */
    public function insert(Connection $connection, string $table, array $data, int $order = 0): void;

    /** @param array<string, mixed> $data */
    public function update(Connection $connection, string $table, int|string $rowId, array $data): void;

    public function delete(Connection $connection, string $table, int|string $rowId): void;

    public function deleteAll(Connection $connection, string $table): void;

    public function count(Connection $connection, string $table): int;
}
