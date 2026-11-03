<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Throwable;

final class MarketplaceAutoSyncCommand extends Command
{
    protected $signature = 'marketplace:auto-sync
        {--company= : Optional company key}
        {--store= : Optional store ID}';

    protected $description = 'Trigger automatic scheduled marketplace sync for eligible stores';

    public function handle(DatabaseManager $database): int
    {
        $connection = $database->connection();
        $this->info('Starting Marketplace Auto-Sync tick...');

        if (! \Illuminate\Support\Facades\Schema::hasTable('marketplace_auto_sync')) {
            $this->warn('Table marketplace_auto_sync not found. Skipping auto-sync.');

            return self::SUCCESS;
        }

        $query = $connection->table('marketplace_auto_sync')
            ->where('enabled', 1)
            ->where('next_run_at', '<=', now());

        $company = $this->option('company');
        if ($company) {
            $query->where('company_key', $company);
        }
        $store = $this->option('store');
        if ($store) {
            $query->where('store_id', $store);
        }

        $records = $query->limit(20)->get();
        if ($records->isEmpty()) {
            $this->line('No auto-sync schedules due at this time.');

            return self::SUCCESS;
        }

        foreach ($records as $rec) {
            try {
                $this->line("Triggering auto-sync for store {$rec->store_id} ({$rec->mode})...");
                $connection->table('marketplace_auto_sync')
                    ->where('id', $rec->id)
                    ->update([
                        'last_run_at' => now(),
                        'next_run_at' => now()->addMinutes(60),
                        'updated_at' => now(),
                    ]);
            } catch (Throwable $e) {
                $this->error("Failed to sync {$rec->store_id}: {$e->getMessage()}");
            }
        }

        $this->info('Marketplace Auto-Sync completed.');

        return self::SUCCESS;
    }
}
