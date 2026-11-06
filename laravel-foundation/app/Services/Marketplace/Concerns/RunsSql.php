<?php

namespace App\Services\Marketplace\Concerns;

use Illuminate\Database\Connection;

/**
 * Raw SQL through Laravel's connection (bindings, query log, one exception path, transactions that nest properly).
 * Rows come back as plain arrays. The using class needs a `DatabaseManager $database` property.
 */
trait RunsSql
{
    protected function db(): Connection
    {
        return $this->database->connection();
    }

    /**
     * @param  array<int|string, mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    protected function rows(string $sql, array $bindings = []): array
    {
        return array_map(static fn (object $row): array => (array) $row, $this->db()->select($sql, $bindings));
    }

    /**
     * @param  array<int|string, mixed>  $bindings
     * @return array<string, mixed>|null
     */
    protected function row(string $sql, array $bindings = []): ?array
    {
        $row = $this->db()->selectOne($sql, $bindings);

        return $row === null ? null : (array) $row;
    }

    /**
     * First column of the first row, or null when there is no row.
     *
     * @param  array<int|string, mixed>  $bindings
     */
    protected function value(string $sql, array $bindings = []): mixed
    {
        $row = $this->db()->selectOne($sql, $bindings);

        return $row === null ? null : (array_values((array) $row)[0] ?? null);
    }

    /**
     * First column of every row.
     *
     * @param  array<int|string, mixed>  $bindings
     * @return list<mixed>
     */
    protected function column(string $sql, array $bindings = []): array
    {
        return array_map(static fn (object $row): mixed => array_values((array) $row)[0] ?? null, $this->db()->select($sql, $bindings));
    }

    /**
     * First column => second column.
     *
     * @param  array<int|string, mixed>  $bindings
     * @return array<int|string, mixed>
     */
    protected function pairs(string $sql, array $bindings = []): array
    {
        $pairs = [];
        foreach ($this->db()->select($sql, $bindings) as $row) {
            $values = array_values((array) $row);
            $pairs[$values[0]] = $values[1] ?? null;
        }

        return $pairs;
    }

    /** @param array<int|string, mixed> $bindings */
    protected function run(string $sql, array $bindings = []): bool
    {
        return $this->db()->statement($sql, $bindings);
    }
}
