<?php

namespace App\Jobs;

use App\Services\Marketplace\MarketplaceSyncCheckpointService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class MarketplaceSyncJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 35;

    public int $maxExceptions = 2;

    public function __construct(
        public readonly string $companyKey,
        public readonly string $storeId,
        public readonly int $connectionId,
        public readonly int $syncJobId,
        public readonly string $runKey,
        public readonly string $requestId,
        public readonly int $page = 1,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('marketplace-sync:'.$this->companyKey.':'.$this->storeId))
                ->releaseAfter(5)
                ->expireAfter((int) config('jago.sync.lease_seconds', 45)),
        ];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 15];
    }

    public function handle(MarketplaceSyncCheckpointService $checkpoint): void
    {
        // The checkpoint service commits the page and inserts the next
        // database-queue payload in one transaction. A queue failure rolls
        // back the checkpoint and lets this payload be retried safely.
        $checkpoint->processPage(
            $this->companyKey,
            $this->storeId,
            $this->connectionId,
            $this->syncJobId,
            $this->runKey,
            $this->requestId,
            $this->page,
        );
    }

    public function failed(Throwable $exception): void
    {
        app(MarketplaceSyncCheckpointService::class)->markFailed($this->syncJobId, $exception);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return [
            'marketplace-sync',
            'company:'.$this->companyKey,
            'store:'.$this->storeId,
            'run:'.$this->runKey,
            'request:'.$this->requestId,
        ];
    }
}
