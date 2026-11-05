<?php

namespace App\Console\Commands;

use App\Services\Marketplace\MarketplaceStoreDeletionService;
use Illuminate\Console\Command;
use Throwable;

final class MarketplaceStoreDeletionCommand extends Command
{
    protected $signature = 'marketplace:store-deletion
        {--store= : Store ID to erase}
        {--pending : Process every queued, running or failed deletion}
        {--install : Install deletion tables and write barriers}
        {--max-batches=200 : Upper bound of batches per store per run}';

    protected $description = 'Erase marketplace stores that have a recorded deletion request, or install the deletion barrier';

    public function handle(MarketplaceStoreDeletionService $service): int
    {
        if ($this->option('install')) {
            $service->install();
            $this->info('Deletion schema ready.');

            return self::SUCCESS;
        }

        if (! $service->ready()) {
            $this->error('Deletion schema is missing. Run with --install first.');

            return self::FAILURE;
        }

        $storeIds = $this->option('pending')
            ? $service->unfinishedStoreIds()
            : array_filter([(string) $this->option('store')]);
        if ($storeIds === []) {
            $this->option('pending') ? $this->line('No pending deletions.') : $this->error('Option --store=STORE_ID or --pending is required.');

            return $this->option('pending') ? self::SUCCESS : self::INVALID;
        }

        $failed = 0;
        foreach ($storeIds as $storeId) {
            try {
                $done = false;
                for ($i = 0, $max = max(1, (int) $this->option('max-batches')); $i < $max && ! $done; $i++) {
                    $done = $service->step($storeId);
                }
                $this->line($storeId.': '.($done ? 'completed' : 'in progress'));
            } catch (Throwable $exception) {
                $failed++;
                $this->error($storeId.': failed ('.$exception->getMessage().')');
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
