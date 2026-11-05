<?php

namespace App\Console\Commands;

use App\Services\Marketplace\MarketplaceSyncDispatchService;
use Illuminate\Console\Command;
use Throwable;

final class MarketplaceSyncCommand extends Command
{
    protected $signature = 'marketplace:sync
        {--company= : Company key; defaults to JL_COMPANY_KEY}
        {--store= : Optional single store ID}
        {--mode=cron : cron or manual}
        {--run-key= : Reusable idempotency key for this run}
        {--request-id= : Correlation ID without secrets}
        {--max-stores= : Maximum stores to enqueue}';

    protected $description = 'Queue one bounded Marketplace order checkpoint per verified store.';

    public function handle(MarketplaceSyncDispatchService $dispatcher): int
    {
        $company = trim((string) ($this->option('company') ?: config('jago.company_key')));
        $store = trim((string) ($this->option('store') ?: ''));
        $mode = strtolower(trim((string) $this->option('mode')));

        if (! in_array($mode, ['cron', 'manual'], true)) {
            $this->error('Mode harus cron atau manual.');

            return self::INVALID;
        }

        $limit = max(1, min(
            (int) ($this->option('max-stores') ?: config('jago.sync.max_stores', 20)),
            (int) config('jago.sync.max_stores_max', 100),
        ));
        $stores = array_slice($dispatcher->eligibleStores($company, $store !== '' ? $store : null), 0, $limit);

        if ($stores === []) {
            $this->line('Tidak ada store Marketplace terverifikasi untuk diantrikan.');

            return self::SUCCESS;
        }

        $runKey = $this->option('run-key') ?: $dispatcher->newRunKey($mode);
        $requestId = $this->option('request-id') ?: $dispatcher->newRequestId();
        $queued = 0;
        $duplicates = 0;
        $failed = 0;

        foreach ($stores as $storeId) {
            try {
                $result = $dispatcher->dispatchForStore($company, $storeId, $mode, (string) $runKey, (string) $requestId);
                if ($result['duplicate']) {
                    $duplicates++;
                    $this->line("duplicate store={$storeId} job={$result['job_id']} status={$result['status']}");
                } else {
                    $queued++;
                    $this->line("queued store={$storeId} job={$result['job_id']} request={$result['request_id']}");
                }
            } catch (Throwable $exception) {
                $failed++;
                $this->warn("failed store={$storeId} error=".$exception::class);
            }
        }

        $this->info("Marketplace sync queued={$queued} duplicate={$duplicates} failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
