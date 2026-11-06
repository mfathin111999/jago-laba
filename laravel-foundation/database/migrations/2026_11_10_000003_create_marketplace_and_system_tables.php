<?php

use App\Database\MarketplaceSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Marketplace tables and the shared system tables (users, migration log). The schema is the golden DDL in
 * database/schema/marketplace.sql; existing tables are left alone. MySQL/MariaDB only: the DDL uses generated
 * columns and MySQL index options, so other drivers (the SQLite test database) build their own tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        MarketplaceSchema::createMissing(DB::connection());
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Migrasi tabel Marketplace tidak bisa dibatalkan di luar local/testing.');
        }
        $connection = DB::connection();
        if (! MarketplaceSchema::supported($connection)) {
            return;
        }
        $connection->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (array_keys(MarketplaceSchema::statements()) as $table) {
            $connection->getSchemaBuilder()->dropIfExists($table);
        }
        $connection->statement('SET FOREIGN_KEY_CHECKS=1');
    }
};
