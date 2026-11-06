<?php

namespace App\Services\Marketplace;

use App\Jobs\MarketplaceLiveSyncJob;
use App\Jobs\MarketplaceSyncJob;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MarketplaceSyncDispatchService
{
    public const JOB_TYPE = 'orders';

    public function __construct(private readonly ?MarketplaceSyncQueuePublisher $publisher = null) {}

    public function newRequestId(): string
    {
        return (string) Str::uuid();
    }

    public function newRunKey(string $mode = 'manual'): string
    {
        $mode = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower(trim($mode))) ?: 'manual';

        return substr($mode.'-'.now()->format('YmdHis').'-'.Str::lower(Str::random(12)), 0, 100);
    }

    /**
     * @return list<string>
     */
    public function eligibleStores(string $companyKey, ?string $storeId = null): array
    {
        $query = DB::table('marketplace_oauth_connections as c')
            ->join('marketplace_stores as s', function ($join): void {
                $join->on('s.id', '=', 'c.store_id')
                    ->on('s.company_key', '=', 'c.company_key');
            })
            ->where('c.company_key', $companyKey)
            ->where('c.status', 'verified')
            ->whereNull('s.archived_at')
            ->orderBy('c.store_id')
            ->select('c.store_id');

        if ($storeId !== null && trim($storeId) !== '') {
            $query->where('c.store_id', trim($storeId));
        }

        return array_map('strval', $query->limit((int) config('jago.sync.max_stores', 20))->pluck('store_id')->all());
    }

    /**
     * @return array{status:string,duplicate:bool,job_id:int,run_key:string,request_id:string,store_id:string,company_key:string}
     */
    public function dispatchForStore(
        string $companyKey,
        string $storeId,
        string $mode = 'manual',
        ?string $runKey = null,
        ?string $requestId = null,
    ): array {
        $companyKey = trim($companyKey);
        $storeId = trim($storeId);

        if ($companyKey === '' || $storeId === '') {
            throw new \InvalidArgumentException('Company dan store wajib diisi.');
        }

        $requestId = $this->normaliseKey($requestId ?: $this->newRequestId(), 'request ID', 100);

        // Live mode: the legacy sync engine reads the official APIs; the queue only drives it step by step.
        $live = app(MarketplaceLiveSyncService::class);
        if ($live->enabled()) {
            $result = $live->enqueue($companyKey, $storeId, $requestId);
            if (! $result['duplicate']) {
                MarketplaceLiveSyncJob::dispatch($companyKey, $storeId, $result['run_key'], $requestId)->onQueue((string) config('jago.sync.queue', 'marketplace'));
            }

            return $result;
        }

        $runKey = $this->normaliseKey($runKey ?: $this->newRunKey($mode), 'run key', 100);
        try {
            $result = DB::transaction(function () use ($companyKey, $storeId, $mode, $runKey, $requestId): array {
                $connection = DB::table('marketplace_oauth_connections as c')
                    ->join('marketplace_stores as s', function ($join): void {
                        $join->on('s.id', '=', 'c.store_id')
                            ->on('s.company_key', '=', 'c.company_key');
                    })
                    ->where('c.company_key', $companyKey)
                    ->where('c.store_id', $storeId)
                    ->where('c.status', 'verified')
                    ->whereNull('s.archived_at')
                    ->select('c.id as connection_id', 'c.platform', 'c.store_id')
                    ->lockForUpdate()
                    ->first();

                if ($connection === null) {
                    throw new \DomainException('Store belum memiliki otorisasi Marketplace yang terverifikasi.');
                }

                $existing = DB::table('marketplace_sync_jobs')
                    ->where('connection_id', $connection->connection_id)
                    ->where('job_type', self::JOB_TYPE)
                    ->where('run_key', $runKey)
                    ->first();

                if ($existing !== null) {
                    return $this->result($existing, true, $companyKey, $storeId, $requestId);
                }

                $checkpoint = [
                    'source' => 'sandbox-local-order-scan',
                    'mode' => $mode,
                    'page' => 1,
                    'page_size' => (int) config('jago.sync.page_size', 50),
                    'max_pages' => (int) config('jago.sync.max_pages', 20),
                    'processed' => 0,
                    'last_id' => null,
                    'request_id' => $requestId,
                ];

                $jobId = DB::table('marketplace_sync_jobs')->insertGetId([
                    'connection_id' => (int) $connection->connection_id,
                    'company_key' => $companyKey,
                    'store_id' => $storeId,
                    'platform' => (string) $connection->platform,
                    'job_type' => self::JOB_TYPE,
                    'run_key' => $runKey,
                    'status' => 'queued',
                    'checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR),
                    'success_count' => 0,
                    'failure_count' => 0,
                    'requested_at' => now(),
                ]);

                $this->queuePublisher()->push(new MarketplaceSyncJob(
                    $companyKey,
                    $storeId,
                    (int) $connection->connection_id,
                    (int) $jobId,
                    $runKey,
                    $requestId,
                    1,
                ));

                return [
                    'status' => 'queued',
                    'duplicate' => false,
                    'job_id' => (int) $jobId,
                    'run_key' => $runKey,
                    'request_id' => $requestId,
                    'store_id' => $storeId,
                    'company_key' => $companyKey,
                ];
            });
        } catch (QueryException $exception) {
            // A concurrent request may win the unique (connection, type, run)
            // key after the pre-insert lookup. Return that winner as a safe
            // idempotent response instead of dispatching a duplicate.
            $existing = DB::table('marketplace_sync_jobs as j')
                ->join('marketplace_oauth_connections as c', 'c.id', '=', 'j.connection_id')
                ->where('c.company_key', $companyKey)
                ->where('c.store_id', $storeId)
                ->where('j.job_type', self::JOB_TYPE)
                ->where('j.run_key', $runKey)
                ->select('j.*')
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            $result = $this->result($existing, true, $companyKey, $storeId, $requestId);
        }

        return $result;
    }

    private function queuePublisher(): MarketplaceSyncQueuePublisher
    {
        return $this->publisher ?? app(MarketplaceSyncQueuePublisher::class);
    }

    /** @return array{status:string,duplicate:bool,job_id:int,run_key:string,request_id:string,store_id:string,company_key:string} */
    private function result(object $job, bool $duplicate, string $companyKey, string $storeId, string $requestId): array
    {
        return [
            'status' => (string) $job->status,
            'duplicate' => $duplicate,
            'job_id' => (int) $job->id,
            'run_key' => (string) $job->run_key,
            'request_id' => $requestId,
            'store_id' => $storeId,
            'company_key' => $companyKey,
        ];
    }

    private function normaliseKey(string $value, string $label, int $maxLength): string
    {
        $value = trim($value);

        if ($value === '' || ! preg_match('/^[A-Za-z0-9._:-]{1,'.$maxLength.'}$/', $value)) {
            throw new \InvalidArgumentException(ucfirst($label).' tidak valid.');
        }

        return $value;
    }
}
