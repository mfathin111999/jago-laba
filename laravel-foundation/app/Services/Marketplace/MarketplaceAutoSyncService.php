<?php

namespace App\Services\Marketplace;

use DomainException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Throwable;

/**
 * Per-store automatic sync schedules (status, new, unpaid, finance, returns).
 * A tick enqueues one run per due schedule on the database queue; it never calls a provider itself
 * and never overlaps runs of the same mode. Success is learned from the finished queue job.
 */
final class MarketplaceAutoSyncService
{
    /** Mode => seconds between successful runs (same cadence as the legacy scheduler). */
    public const MODES = ['status' => 300, 'new' => 60, 'unpaid' => 60, 'finance' => 300, 'returns' => 900];

    private const RETRY_SECONDS = 300;

    private const PLATFORMS = ['TikTok Shop', 'Shopee'];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly MarketplaceSyncDispatchService $dispatcher,
    ) {}

    public function available(): bool
    {
        return true;
    }

    /**
     * @return array<string, array{mode: string, enabled: bool, next_run_at: mixed, last_success_at: mixed, last_error_code: mixed}>
     */
    public function states(string $companyKey, string $storeId): array
    {
        $states = [];
        foreach (array_keys(self::MODES) as $mode) {
            $states[$mode] = ['mode' => $mode, 'enabled' => false, 'next_run_at' => null, 'last_success_at' => null, 'last_error_code' => null];
        }

        $rows = $this->database->connection()->table('marketplace_auto_sync')
            ->where('store_id', $storeId)->where('company_key', $companyKey)
            ->get(['mode', 'enabled', 'next_run_at', 'last_success_at', 'last_error_code']);
        foreach ($rows as $row) {
            if (isset(self::MODES[$row->mode])) {
                $states[$row->mode] = ['mode' => (string) $row->mode, 'enabled' => (bool) $row->enabled, 'next_run_at' => $row->next_run_at, 'last_success_at' => $row->last_success_at, 'last_error_code' => $row->last_error_code];
            }
        }

        return $states;
    }

    /**
     * @return array<string, array{mode: string, enabled: bool, next_run_at: mixed, last_success_at: mixed, last_error_code: mixed}>
     */
    public function set(string $companyKey, string $storeId, string $mode, bool $enabled): array
    {
        if (! isset(self::MODES[$mode])) {
            throw new InvalidArgumentException('Pengaturan tidak dikenal.');
        }

        $connection = $this->database->connection();
        if ($enabled && ! $this->verifiedConnectionExists($companyKey, $storeId)) {
            throw new DomainException('Otorisasi toko diperlukan sebelum mengaktifkan auto sync.');
        }

        $key = ['store_id' => $storeId, 'company_key' => $companyKey, 'mode' => $mode];
        $values = ['enabled' => $enabled ? 1 : 0, 'next_run_at' => now(), 'last_error_code' => null, 'updated_at' => now()];
        $connection->transaction(function () use ($connection, $key, $values): void {
            $table = $connection->table('marketplace_auto_sync')->where($key);
            if ($table->lockForUpdate()->exists()) {
                $connection->table('marketplace_auto_sync')->where($key)->update($values);
            } else {
                $connection->table('marketplace_auto_sync')->insert($key + $values);
            }
        });

        return $this->states($companyKey, $storeId);
    }

    /**
     * Enqueues one run per due schedule. Returns the number of runs enqueued.
     */
    public function tick(?string $companyKey = null, ?string $storeId = null, int $limit = 20): int
    {
        $connection = $this->database->connection();
        $due = $connection->table('marketplace_auto_sync as a')
            ->join('marketplace_stores as s', function ($join): void {
                $join->on('s.id', '=', 'a.store_id')->on('s.company_key', '=', 'a.company_key');
            })
            ->where('a.enabled', 1)->whereNull('s.archived_at')
            ->where(function ($query): void {
                $query->whereNull('a.next_run_at')->orWhere('a.next_run_at', '<=', now());
            })
            ->when($companyKey, fn ($query) => $query->where('a.company_key', $companyKey))
            ->when($storeId, fn ($query) => $query->where('a.store_id', $storeId))
            ->orderBy('a.next_run_at')->orderBy('a.mode')->limit(max(1, $limit))
            ->get(['a.store_id', 'a.company_key', 'a.mode', 'a.last_success_at']);

        $enqueued = 0;
        foreach ($due as $row) {
            if ($this->runSchedule($row)) {
                $enqueued++;
            }
        }

        return $enqueued;
    }

    private function runSchedule(object $row): bool
    {
        $connection = $this->database->connection();
        $where = ['store_id' => $row->store_id, 'company_key' => $row->company_key, 'mode' => $row->mode];
        $prefix = 'auto-'.$row->mode.'-%';
        $latest = $connection->table('marketplace_sync_jobs')
            ->where('company_key', $row->company_key)->where('store_id', $row->store_id)->where('run_key', 'like', $prefix)
            ->orderByDesc('id')->first(['status', 'finished_at', 'last_error_code']);

        // A previous run of this mode is still queued or running: never overlap, look again shortly.
        if ($latest !== null && in_array($latest->status, ['queued', 'running'], true)) {
            $connection->table('marketplace_auto_sync')->where($where)->update(['next_run_at' => now()->addSeconds(60)]);

            return false;
        }

        $lastSuccess = $row->last_success_at;
        $errorCode = null;
        if ($latest !== null && $latest->status === 'succeeded' && $latest->finished_at !== null) {
            $lastSuccess = $latest->finished_at;
        } elseif ($latest !== null && $latest->status === 'failed') {
            $errorCode = $this->safeCode($latest->last_error_code);
        }

        try {
            if (! $this->verifiedConnectionExists($row->company_key, $row->store_id)) {
                throw new DomainException('REAUTHORIZE_REQUIRED');
            }
            $this->dispatcher->dispatchForStore($row->company_key, $row->store_id, 'auto-'.$row->mode);
            $delay = $errorCode === null ? self::MODES[$row->mode] : self::RETRY_SECONDS;
            $dispatched = true;
        } catch (Throwable $exception) {
            $errorCode = $exception instanceof DomainException && $exception->getMessage() === 'REAUTHORIZE_REQUIRED' ? 'REAUTHORIZE_REQUIRED' : 'AUTO_SYNC_FAILED';
            $delay = self::RETRY_SECONDS;
            $dispatched = false;
        }

        $connection->table('marketplace_auto_sync')->where($where)->update([
            'next_run_at' => now()->addSeconds($delay),
            'last_success_at' => $lastSuccess,
            'last_error_code' => $errorCode,
            'updated_at' => now(),
        ]);

        return $dispatched;
    }

    private function verifiedConnectionExists(string $companyKey, string $storeId): bool
    {
        return $this->database->connection()->table('marketplace_oauth_connections as c')
            ->join('marketplace_stores as s', function ($join): void {
                $join->on('s.id', '=', 'c.store_id')->on('s.company_key', '=', 'c.company_key');
            })
            ->where('c.store_id', $storeId)->where('c.company_key', $companyKey)
            ->where('c.status', 'verified')->whereNull('s.archived_at')
            ->whereIn('c.platform', self::PLATFORMS)->exists();
    }

    private function safeCode(?string $code): string
    {
        return $code !== null && preg_match('/^[A-Z0-9_.-]{1,100}$/', $code) ? $code : 'AUTO_SYNC_FAILED';
    }
}
