<?php

namespace App\Pilots\Marketplace;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Drives the legacy sync engine one step at a time and schedules itself again while the run has work left. */
final class MarketplaceLiveSyncJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly string $companyKey,
        public readonly string $storeId,
        public readonly string $runKey,
        public readonly string $requestId,
        public readonly int $iteration = 1,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('marketplace-live-sync:'.$this->companyKey.':'.$this->storeId))->releaseAfter(5)->expireAfter(300)];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(MarketplaceLiveSyncService $sync): void
    {
        $result = $sync->step($this->companyKey, $this->storeId, $this->runKey);
        if (! $result['pending']) {
            return;
        }
        if ($this->iteration >= MarketplaceLiveSyncService::MAX_STEPS) {
            Log::warning('Marketplace live sync stopped at the step cap.', ['store' => $this->storeId, 'run' => $this->runKey]);

            return;
        }

        self::dispatch($this->companyKey, $this->storeId, $this->runKey, $this->requestId, $this->iteration + 1)
            ->onQueue((string) config('jago.sync.queue', 'marketplace'))
            ->delay(now()->addSeconds($result['retry_in']));
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Marketplace live sync step failed.', ['store' => $this->storeId, 'run' => $this->runKey, 'exception' => $exception::class]);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['marketplace-live-sync', 'company:'.$this->companyKey, 'store:'.$this->storeId, 'run:'.$this->runKey];
    }
}
