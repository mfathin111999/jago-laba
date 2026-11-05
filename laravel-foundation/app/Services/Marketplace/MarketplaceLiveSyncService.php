<?php

namespace App\Services\Marketplace;

use App\Auth\LegacyUser;
use App\Marketplace\LegacyExecutionContext;
use App\Marketplace\LegacyRuntime;
use DomainException;
use MarketplaceSyncService;
use PDO;

/**
 * Live synchronisation: runs the LEGACY sync engine (MarketplaceSyncService) unchanged. It reads products, orders,
 * transactions and returns from the official Shopee / TikTok Shop APIs with the same paging, checkpoints, retry and
 * cool-down rules as the legacy application, writing into the same tables. Laravel only supplies the queue that
 * drives it one step at a time.
 */
final class MarketplaceLiveSyncService
{
    /** Safety cap on steps per run so a stuck run cannot loop forever. */
    public const MAX_STEPS = 2000;

    /** @var (callable(array<string, mixed>): object)|null provider client factory; tests inject a fake, production leaves it null */
    private $clientFactory = null;

    public function __construct(private readonly LegacyExecutionContext $context) {}

    /** @param callable(array<string, mixed>): object|null $factory */
    public function useClientFactory(?callable $factory): void
    {
        $this->clientFactory = $factory;
    }

    public function enabled(): bool
    {
        return strtolower((string) config('jago.oauth.mode', 'sandbox')) === 'live';
    }

    /**
     * Creates (or reuses) the run for a store and returns its first job.
     *
     * @return array{status:string,duplicate:bool,job_id:int,run_key:string,request_id:string,store_id:string,company_key:string}
     */
    public function enqueue(string $companyKey, string $storeId, string $requestId): array
    {
        $state = $this->run($companyKey, static function (MarketplaceSyncService $sync) use ($storeId): array {
            $before = $sync->status($storeId)['jobs'] ?? [];
            $pendingBefore = array_filter($before, static fn (array $job): bool => in_array($job['status'], ['queued', 'running'], true));
            $after = $sync->enqueueAll($storeId);

            return ['state' => $after, 'duplicate' => $pendingBefore !== []];
        });
        $jobs = $state['state']['jobs'] ?? [];
        if ($jobs === []) {
            throw new DomainException('Sinkronisasi belum dapat dijadwalkan untuk toko ini.');
        }
        $first = $jobs[0];

        return [
            'status' => (string) $first['status'],
            'duplicate' => (bool) $state['duplicate'],
            'job_id' => (int) $first['id'],
            'run_key' => (string) $first['run_key'],
            'request_id' => $requestId,
            'store_id' => $storeId,
            'company_key' => $companyKey,
        ];
    }

    /**
     * Runs one engine step and reports whether the run still has work.
     *
     * @return array{pending: bool, retry_in: int, jobs: list<array<string, mixed>>}
     */
    public function step(string $companyKey, string $storeId, string $runKey): array
    {
        $state = $this->run($companyKey, static fn (MarketplaceSyncService $sync): array => $sync->step($storeId, $runKey));
        $jobs = array_values(array_filter($state['jobs'] ?? [], static fn (array $job): bool => (string) $job['run_key'] === $runKey));
        $pending = false;
        $retryIn = 1;
        foreach ($jobs as $job) {
            if (! in_array($job['status'], ['queued', 'running'], true)) {
                continue;
            }
            $pending = true;
            $checkpoint = json_decode((string) ($job['checkpoint'] ?? ''), true) ?: [];
            $next = (int) ($checkpoint['_retry']['next_at'] ?? 0);
            $retryIn = max($retryIn, $next > time() ? min(900, $next - time()) : 1);
        }

        return ['pending' => $pending, 'retry_in' => $retryIn, 'jobs' => $jobs];
    }

    /**
     * @template T
     *
     * @param  callable(MarketplaceSyncService): T  $callback
     * @return T
     */
    private function run(string $companyKey, callable $callback): mixed
    {
        LegacyRuntime::prepare();
        LegacyRuntime::service('MarketplaceSyncService');
        $system = new LegacyUser(['id' => 'system', 'username' => 'system', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN', 'company_key' => $companyKey]);

        $factory = $this->clientFactory;

        return $this->context->run($system, $companyKey, static function (PDO $pdo) use ($callback, $factory): mixed {
            // connectedManual: serve real connected stores (not only sandbox ones), same as the legacy manual sync.
            return $callback(new MarketplaceSyncService($pdo, $factory, false, false, true));
        });
    }
}
