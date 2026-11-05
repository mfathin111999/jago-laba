<?php

namespace App\Console\Commands;

use App\Services\Marketplace\MarketplaceAutoSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class MarketplaceAutoSyncCommand extends Command
{
    protected $signature = 'marketplace:auto-sync
        {--company= : Optional company key}
        {--store= : Optional store ID}
        {--limit=20 : Maximum schedules to enqueue per run}';

    protected $description = 'Enqueue a sync run for every due automatic sync schedule';

    public function handle(MarketplaceAutoSyncService $service): int
    {
        if (! Schema::hasTable('marketplace_auto_sync')) {
            $this->warn('Table marketplace_auto_sync not found. Skipping auto-sync.');

            return self::SUCCESS;
        }

        try {
            $enqueued = $service->tick(
                $this->option('company') ?: null,
                $this->option('store') ?: null,
                max(1, (int) $this->option('limit')),
            );
        } catch (Throwable $exception) {
            $this->error('Auto-sync failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line($enqueued === 0 ? 'No auto-sync schedules due at this time.' : "Enqueued {$enqueued} auto-sync run(s).");

        return self::SUCCESS;
    }
}
