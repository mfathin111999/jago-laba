<?php

namespace App\Services\Marketplace;

use App\Jobs\MarketplaceSyncJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class MarketplaceSyncCheckpointService
{
    public function __construct(private readonly ?MarketplaceSyncQueuePublisher $publisher = null) {}

    /**
     * Scan one bounded local/sandbox page. No provider call and no order row
     * write occurs in this pilot; only marketplace_sync_jobs is checkpointed.
     *
     * @return array{skipped:bool,has_more:bool,next_page:int,processed:int}
     */
    public function processPage(
        string $companyKey,
        string $storeId,
        int $connectionId,
        int $jobId,
        string $runKey,
        string $requestId,
        int $page,
    ): array {
        $claim = DB::transaction(function () use ($companyKey, $storeId, $connectionId, $jobId, $runKey, $requestId, $page): array {
            $job = DB::table('marketplace_sync_jobs')
                ->where('id', $jobId)
                ->where('connection_id', $connectionId)
                ->where('company_key', $companyKey)
                ->where('store_id', $storeId)
                ->where('run_key', $runKey)
                ->where('job_type', MarketplaceSyncDispatchService::JOB_TYPE)
                ->lockForUpdate()
                ->first();

            if ($job === null) {
                throw new \RuntimeException('Checkpoint sinkronisasi tidak ditemukan.');
            }

            // A failed database-queue payload may be reintroduced explicitly
            // through `queue:retry`; only a succeeded checkpoint is terminal.
            if ((string) $job->status === 'succeeded') {
                return ['skipped' => true, 'checkpoint' => [], 'page_size' => 0];
            }

            $checkpoint = json_decode((string) ($job->checkpoint ?? ''), true) ?: [];
            $currentPage = max(1, (int) ($checkpoint['page'] ?? 1));

            if ($currentPage !== $page) {
                return ['skipped' => true, 'checkpoint' => $checkpoint, 'page_size' => 0];
            }

            if ((string) $job->status === 'running' && $job->started_at !== null) {
                $startedAt = CarbonImmutable::parse((string) $job->started_at);
                if ($startedAt->greaterThan(now()->subSeconds((int) config('jago.sync.lease_seconds', 45)))) {
                    return ['skipped' => true, 'checkpoint' => $checkpoint, 'page_size' => 0];
                }
            }

            $checkpoint['request_id'] = $requestId;
            DB::table('marketplace_sync_jobs')->where('id', $jobId)->update([
                'status' => 'running',
                'started_at' => now(),
                'finished_at' => null,
                'last_error_code' => null,
                'checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR),
            ]);

            return [
                'skipped' => false,
                'checkpoint' => $checkpoint,
                'page_size' => min(
                    max(1, (int) ($checkpoint['page_size'] ?? config('jago.sync.page_size', 50))),
                    (int) config('jago.sync.page_size_max', 100),
                ),
            ];
        });

        if ($claim['skipped']) {
            return ['skipped' => true, 'has_more' => false, 'next_page' => $page, 'processed' => 0];
        }

        $checkpoint = $claim['checkpoint'];
        $pageSize = (int) $claim['page_size'];
        $maxPages = min(
            max(1, (int) ($checkpoint['max_pages'] ?? config('jago.sync.max_pages', 20))),
            (int) config('jago.sync.max_pages_max', 100),
        );

        try {
            $ids = DB::table('marketplace_orders')
                ->where('store_id', $storeId)
                ->orderBy('id')
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize + 1)
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->all();

            $hasMore = count($ids) > $pageSize && $page < $maxPages;
            $processedIds = array_slice($ids, 0, $pageSize);
            $processed = count($processedIds);
            $nextPage = $hasMore ? $page + 1 : $page;
            $checkpoint['page'] = $nextPage;
            $checkpoint['processed'] = (int) ($checkpoint['processed'] ?? 0) + $processed;
            $checkpoint['last_id'] = $processedIds !== [] ? end($processedIds) : ($checkpoint['last_id'] ?? null);
            $checkpoint['has_more'] = $hasMore;
            $checkpoint['completed_at'] = $hasMore ? null : now()->toIso8601String();

            DB::transaction(function () use ($companyKey, $storeId, $connectionId, $jobId, $runKey, $requestId, $checkpoint, $processed, $hasMore, $nextPage): void {
                $updated = DB::table('marketplace_sync_jobs')
                    ->where('id', $jobId)
                    ->where('status', 'running')
                    ->update([
                        'status' => $hasMore ? 'queued' : 'succeeded',
                        'checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR),
                        'success_count' => DB::raw('success_count + '.(int) $processed),
                        'finished_at' => $hasMore ? null : now(),
                        'last_error_code' => null,
                    ]);

                if ($updated !== 1) {
                    throw new \RuntimeException('Checkpoint sinkronisasi berubah sebelum commit.');
                }

                if ($hasMore) {
                    $this->queuePublisher()->push(new MarketplaceSyncJob(
                        $companyKey,
                        $storeId,
                        $connectionId,
                        $jobId,
                        $runKey,
                        $requestId,
                        $nextPage,
                    ));
                }
            });

            return ['skipped' => false, 'has_more' => $hasMore, 'next_page' => $nextPage, 'processed' => $processed];
        } catch (Throwable $exception) {
            $this->releaseForRetry($jobId, $this->safeErrorCode($exception));
            throw $exception;
        }
    }

    public function releaseForRetry(int $jobId, string $errorCode = 'SYNC_RETRYABLE'): void
    {
        DB::table('marketplace_sync_jobs')
            ->where('id', $jobId)
            ->where('status', 'running')
            ->update([
                'status' => 'queued',
                'failure_count' => DB::raw('failure_count + 1'),
                'last_error_code' => $this->safeErrorCode(new \RuntimeException($errorCode)),
                'finished_at' => null,
            ]);
    }

    public function markFailed(int $jobId, Throwable $exception): void
    {
        DB::table('marketplace_sync_jobs')
            ->where('id', $jobId)
            ->where('status', '<>', 'succeeded')
            ->update([
                'status' => 'failed',
                'last_error_code' => $this->safeErrorCode($exception, 'QUEUE_FAILED'),
                'finished_at' => now(),
            ]);
    }

    private function queuePublisher(): MarketplaceSyncQueuePublisher
    {
        return $this->publisher ?? app(MarketplaceSyncQueuePublisher::class);
    }

    private function safeErrorCode(Throwable $exception, string $fallback = 'SYNC_RETRYABLE'): string
    {
        $code = is_string($exception->getCode()) ? $exception->getCode() : '';

        return preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $code) ? $code : $fallback;
    }
}
