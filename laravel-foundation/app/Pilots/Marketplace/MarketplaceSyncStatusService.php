<?php

namespace App\Pilots\Marketplace;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read model behind the legacy marketplace-sync endpoint: store preview and queue status.
 * Reads local data only; it never calls a marketplace provider.
 */
final class MarketplaceSyncStatusService
{
    private const PENDING = ['queued', 'running'];

    private const JOB_LIMIT = 10;

    /**
     * @return list<array<string, mixed>>
     */
    public function previewStores(string $companyKey, ?string $storeId = null): array
    {
        $query = DB::table('marketplace_stores as s')
            ->join('marketplace_oauth_connections as c', function ($join): void {
                $join->on('c.store_id', '=', 's.id')->on('c.company_key', '=', 's.company_key');
            })
            ->where('s.company_key', $companyKey)
            ->whereNull('s.archived_at')
            ->where('c.status', 'verified')
            ->whereIn('s.platform', ['TikTok Shop', 'Shopee'])
            ->orderBy('s.id')
            ->select(['s.id', 's.store_name', 's.platform', 'c.external_store_name', 'c.last_successful_sync_at']);
        if (Schema::hasColumn('marketplace_stores', 'sync_history_start_at')) {
            $query->addSelect('s.sync_history_start_at');
        }
        if ($storeId !== null && $storeId !== '') {
            $query->where('s.id', $storeId);
        }

        return $query->get()->map(function ($row): array {
            $store = (array) $row;
            $window = $this->window();
            if (! empty($store['sync_history_start_at'])) {
                $window['window_start'] = (new DateTimeImmutable((string) $store['sync_history_start_at'], new DateTimeZone('Asia/Jakarta')))->getTimestamp();
            }
            unset($store['sync_history_start_at']);
            $store['order_count'] = DB::table('marketplace_orders')->where('store_id', $store['id'])->count();
            $store['window'] = $window;

            return $store;
        })->all();
    }

    /**
     * @return array{jobs: list<array<string, mixed>>, returns: list<array<string, mixed>>, pending: int, failed: int, can_close: bool}
     */
    public function status(string $companyKey, string $storeId): array
    {
        $jobs = DB::table('marketplace_sync_jobs')
            ->where('company_key', $companyKey)->where('store_id', $storeId)
            ->orderByDesc('id')->limit(self::JOB_LIMIT)->get()
            ->map(function ($job): array {
                $checkpoint = json_decode((string) ($job->checkpoint ?? ''), true) ?: [];

                return [
                    'id' => (int) $job->id,
                    'job_type' => (string) $job->job_type,
                    'status' => (string) $job->status,
                    'phase' => (string) $job->status,
                    'counts' => is_array($checkpoint['counts'] ?? null) ? $checkpoint['counts'] : [],
                    'processed' => (int) ($checkpoint['processed'] ?? 0),
                    'success_count' => (int) $job->success_count,
                    'failure_count' => (int) $job->failure_count,
                    'last_error_code' => $job->last_error_code,
                    'run_key' => (string) $job->run_key,
                    'requested_at' => $job->requested_at,
                    'finished_at' => $job->finished_at,
                ];
            })->all();

        $statuses = array_column($jobs, 'status');

        return [
            'jobs' => $jobs,
            'returns' => [],
            'pending' => count(array_filter($statuses, fn (string $status) => in_array($status, self::PENDING, true))),
            'failed' => count(array_filter($statuses, fn (string $status) => $status === 'failed')),
            // A database/queue worker (scheduler-driven) processes the jobs, so the page may be closed.
            'can_close' => config('queue.default') !== 'sync',
        ];
    }

    public function hasPendingJob(string $companyKey, string $storeId): bool
    {
        return DB::table('marketplace_sync_jobs')
            ->where('company_key', $companyKey)->where('store_id', $storeId)
            ->whereIn('status', self::PENDING)->exists();
    }

    /**
     * Calendar-month window in Asia/Jakarta, same policy as the legacy MarketplaceSyncWindow.
     *
     * @return array{policy: string, window_start: int, window_end: int}
     */
    private function window(): array
    {
        $end = time();
        $start = (new DateTimeImmutable('@'.$end))->setTimezone(new DateTimeZone('Asia/Jakarta'))
            ->modify('first day of this month')->setTime(0, 0)->getTimestamp();

        return ['policy' => 'calendar_month', 'window_start' => $start, 'window_end' => $end];
    }
}
