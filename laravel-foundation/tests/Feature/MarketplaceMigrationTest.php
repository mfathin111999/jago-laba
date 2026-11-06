<?php

namespace Tests\Feature;

use App\Database\MarketplaceSchema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MarketplaceMigrationTest extends TestCase
{
    public function test_the_golden_schema_defines_every_marketplace_and_system_table(): void
    {
        $columns = MarketplaceSchema::columns();

        $this->assertCount(46, $columns);
        foreach (['marketplace_orders', 'marketplace_stores', 'marketplace_sync_jobs', 'system_users', 'auth_users_v2', 'migration_log'] as $table) {
            $this->assertArrayHasKey($table, $columns);
        }
        $this->assertContains('net_margin_profit', $columns['marketplace_orders']);
        $this->assertContains('roas', $columns['marketplace_ad_spends']);
    }

    public function test_every_statement_only_creates_a_missing_table(): void
    {
        foreach (MarketplaceSchema::statements() as $table => $ddl) {
            $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS `'.$table.'`', $ddl);
            $this->assertStringNotContainsString('AUTO_INCREMENT=', $ddl);
        }
    }

    public function test_the_mysql_ddl_is_skipped_on_other_drivers(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(0, MarketplaceSchema::createMissing(DB::connection()));
    }

    public function test_schema_check_reports_missing_tables_and_exits_with_failure(): void
    {
        $this->assertSame(1, Artisan::call('marketplace:schema-check'));
        $this->assertStringContainsString('Tabel hilang: marketplace_orders', Artisan::output());
    }
}
