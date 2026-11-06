<?php

namespace App\Database;

use Illuminate\Database\ConnectionInterface;

/**
 * The Marketplace and shared system tables, as the golden DDL in database/schema/marketplace.sql
 * (captured from a database built by the legacy installer, including generated columns and indexes).
 * Applied with CREATE TABLE IF NOT EXISTS, so an existing table is never changed.
 */
final class MarketplaceSchema
{
    public const FILE = 'database/schema/marketplace.sql';

    /** @return array<string, string> table => CREATE TABLE statement */
    public static function statements(): array
    {
        $sql = (string) file_get_contents(base_path(self::FILE));
        $statements = [];
        foreach (preg_split('/^-- table: /m', $sql) as $block) {
            $block = trim($block);
            if ($block === '' || str_starts_with($block, '--')) {
                continue;
            }
            [$table, $ddl] = explode("\n", $block, 2);
            $statements[trim($table)] = rtrim(trim($ddl), ';');
        }

        return $statements;
    }

    /** @return array<string, list<string>> table => column names, read from the DDL */
    public static function columns(): array
    {
        $columns = [];
        foreach (self::statements() as $table => $ddl) {
            preg_match_all('/^\s+`([^`]+)`\s/m', $ddl, $found);
            $columns[$table] = $found[1];
        }

        return $columns;
    }

    public static function supported(ConnectionInterface $connection): bool
    {
        return in_array($connection->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /** Creates the missing tables. Foreign keys reference tables that the legacy database never had, so checks are off meanwhile. */
    public static function createMissing(ConnectionInterface $connection): int
    {
        if (! self::supported($connection)) {
            return 0;
        }
        $schema = $connection->getSchemaBuilder();
        $created = 0;
        $connection->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (self::statements() as $table => $ddl) {
                if (! $schema->hasTable($table)) {
                    $connection->statement($ddl);
                    $created++;
                }
            }
        } finally {
            $connection->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return $created;
    }
}
