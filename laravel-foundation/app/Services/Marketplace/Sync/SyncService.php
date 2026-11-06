<?php

namespace App\Services\Marketplace\Sync;

use App\Exceptions\MarketplaceIntegrationException;
use App\Marketplace\IntegrationPolicy;
use App\Marketplace\TokenVault;
use App\Services\Marketplace\Concerns\RunsSql;
use App\Services\Marketplace\Integrations\MarketplaceReadAdapter;
use App\Services\Marketplace\Integrations\ShopeeReadAdapter;
use App\Services\Marketplace\Integrations\TikTokDataClient;
use App\Services\Marketplace\MarketplaceScope;
use App\Services\Marketplace\MarketplaceStoreDeletionService;
use App\Services\Marketplace\Stock\StockService;
use DomainException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

final class SyncService
{
    use RunsSql;

    /** @var (callable(array<string, mixed>): object)|null provider client factory; tests inject a fake, production leaves it null */
    private $factory = null;

    private bool $orderAutomation = false;

    private bool $catalogOnly = false;

    private bool $connectedManual = false;

    private array $readEvidence = [];

    public const TYPES = ['products', 'orders', 'transactions', 'returns'];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly PartnerSettings $settings,
        private readonly TokenRefresher $tokens,
        private readonly IntegrationRuntime $runtime,
        private readonly IncrementalSync $incremental,
        private readonly MarketplaceScope $scope,
        private readonly TokenVault $vault,
        private readonly MarketplaceStoreDeletionService $deletion,
    ) {}

    /**
     * A copy of this service with the run options set: how the run is started (order automation, catalog only,
     * connected store from the manual screen) and, for tests, a fake provider client factory.
     *
     * @param  (callable(array<string, mixed>): object)|null  $factory
     */
    public function with(bool $orderAutomation = false, bool $catalogOnly = false, bool $connectedManual = false, ?callable $factory = null): static
    {
        $copy = clone $this;
        [$copy->orderAutomation, $copy->catalogOnly, $copy->connectedManual, $copy->factory] = [$orderAutomation, $catalogOnly, $connectedManual, $factory];

        return $copy;
    }

    public function stores(): array
    {

        return array_values(array_filter($this->rows("SELECT c.store_id,c.external_store_name,c.status,c.token_expires_at,c.last_successful_sync_at,s.platform FROM marketplace_oauth_connections c JOIN marketplace_stores s ON s.id=c.store_id AND s.company_key=c.company_key WHERE c.company_key=? AND c.platform IN ('TikTok Shop','Shopee') AND s.archived_at IS NULL ORDER BY c.external_store_name", [$this->scope->companyKey()]), fn ($row) => $this->connectedManual ? $row['status'] === 'verified' : str_starts_with(strtoupper($row['external_store_name']), 'SANDBOX')));
    }

    private function connection(string $id, bool $lock = false): array
    {
        $this->scope->assertStoreAccess($id);
        $row = $this->row('SELECT * FROM marketplace_oauth_connections WHERE store_id=? AND company_key=?'.($lock ? ' FOR UPDATE' : ''), [$id, $this->scope->companyKey()]);
        if ($this->deletion->blocked($id)) {
            throw new DomainException('Toko tidak tersedia untuk sinkronisasi.');
        }
        if (! $row || $row['status'] !== 'verified' || ! in_array($row['platform'], ['TikTok Shop', 'Shopee'], true) || (! $this->orderAutomation && ! $this->connectedManual && ($row['platform'] !== 'TikTok Shop' || ! str_starts_with(strtoupper($row['external_store_name']), 'SANDBOX')))) {
            throw new DomainException('Pilih toko terhubung dengan otorisasi yang masih terverifikasi.');
        }

        return $row;
    }

    public function enqueueCatalog(string $id): string
    {
        if (! $this->orderAutomation) {
            throw new DomainException('Jalur katalog diperlukan.');
        }
        $this->db()->beginTransaction();
        try {
            $c = $this->connection($id, true);
            $run = $this->value("SELECT run_key FROM marketplace_sync_jobs WHERE connection_id=? AND run_key LIKE 'auto-catalog-%' AND status IN ('queued','running') LIMIT 1", [$c['id']]);
            if (! $run) {
                $run = 'auto-catalog-'.bin2hex(random_bytes(8));
                $this->run("INSERT INTO marketplace_sync_jobs(connection_id,company_key,store_id,platform,job_type,run_key,status,checkpoint) VALUES (?,?,?,?,'products',?,'queued',?)", [$c['id'], $c['company_key'], $id, $c['platform'], $run, json_encode($this->plan($c, 'products'), JSON_THROW_ON_ERROR)]);
            }
            $this->db()->commit();

            return $run;
        } catch (Throwable $e) {
            if (($this->db()->transactionLevel() > 0)) {
                $this->db()->rollBack();
            }throw $e;
        }
    }

    public function enqueueReturns(string $id): string
    {
        if (! $this->orderAutomation) {
            throw new DomainException('Jalur otomatis diperlukan.');
        }
        $this->db()->beginTransaction();
        try {
            $c = $this->connection($id, true);
            if ($c['platform'] !== 'TikTok Shop') {
                throw new MarketplaceIntegrationException('Konektor Return/Refund Shopee belum terverifikasi.', 'RETURN_CONNECTOR_UNAVAILABLE');
            }
            $run = $this->value("SELECT run_key FROM marketplace_sync_jobs WHERE connection_id=? AND job_type='returns' AND status IN ('queued','running') LIMIT 1", [$c['id']]);
            if (! $run) {
                $run = 'auto-returns-'.bin2hex(random_bytes(8));
                $this->run("INSERT INTO marketplace_sync_jobs(connection_id,company_key,store_id,platform,job_type,run_key,status,checkpoint) VALUES (?,?,?,?,'returns',?,'queued',?)", [$c['id'], $c['company_key'], $id, $c['platform'], $run, json_encode($this->plan($c, 'returns'), JSON_THROW_ON_ERROR)]);
            }
            $this->db()->commit();

            return $run;
        } catch (Throwable $e) {
            if (($this->db()->transactionLevel() > 0)) {
                $this->db()->rollBack();
            }throw $e;
        }
    }

    public function enqueueFinance(string $id): string
    {
        if (! $this->orderAutomation) {
            throw new DomainException('Jalur otomatis diperlukan.');
        }
        $this->db()->beginTransaction();
        try {
            $c = $this->connection($id, true);
            if ($c['platform'] !== 'TikTok Shop' && $c['platform'] !== 'Shopee') {
                throw new DomainException('Konektor finance toko belum tersedia.');
            }
            $run = $this->value("SELECT run_key FROM marketplace_sync_jobs WHERE connection_id=? AND run_key LIKE 'auto-finance-%' AND status IN ('queued','running') LIMIT 1", [$c['id']]);
            if (! $run) {
                $run = 'auto-finance-'.bin2hex(random_bytes(8));
                $cp = $this->plan($c, 'transactions');
                $cp['finance_stage'] = 'unsettled';
                $this->run("INSERT INTO marketplace_sync_jobs(connection_id,company_key,store_id,platform,job_type,run_key,status,checkpoint) VALUES (?,?,?,?,'transactions',?,'queued',?)", [$c['id'], $c['company_key'], $id, $c['platform'], $run, json_encode($cp, JSON_THROW_ON_ERROR)]);
            }
            $this->db()->commit();

            return $run;
        } catch (Throwable $e) {
            if (($this->db()->transactionLevel() > 0)) {
                $this->db()->rollBack();
            }throw $e;
        }
    }

    public function refreshOrderFinance(string $store, string $orderId): bool
    {
        if (! $this->orderAutomation) {
            throw new DomainException('Jalur finance diperlukan.');
        }
        $c = $this->connection($store);
        if ($c['platform'] !== 'TikTok Shop' && $c['platform'] !== 'Shopee') {
            return false;
        }
        $order = $this->row("SELECT order_date,transaction_detail_json FROM marketplace_orders WHERE store_id=? AND id=? AND source_kind='api'", [$store, $orderId]);
        if (! $order) {
            throw new DomainException('Pesanan tidak ditemukan.');
        }
        if (! empty($c['token_expires_at']) && strtotime($c['token_expires_at']) <= time() + 60) {
            $this->tokens->refreshConnection($store, false);
            $c = $this->connection($store);
        }
        if ($c['platform'] === 'Shopee') {
            $config = $this->settings->configFor('Shopee');
            $client = $this->factory ? ($this->factory)($config) : new ShopeeReadAdapter($config, (string) $c['external_store_id']);
            $token = $this->vault->decrypt($c['access_token_encrypted']);
            try {
                $f = $this->readCall($client, $c, 'GET', '/api/v2/payment/get_escrow_detail', ['order_sn' => $orderId], $token);
            } catch (Throwable $e) {
                return false;
            }
            if (empty($f['order_income'])) {
                return false;
            }
            $this->db()->beginTransaction();
            try {
                $this->connection($store, true);
                $this->saveRecord($store, ['type' => 'shopee_escrow', 'id' => $orderId, 'data' => $f]);
                $this->db()->commit();

                return true;
            } catch (Throwable $e) {
                if (($this->db()->transactionLevel() > 0)) {
                    $this->db()->rollBack();
                }throw $e;
            }
        }
        $config = $this->settings->configFor('TikTok Shop');
        $client = $this->factory ? ($this->factory)($config) : new TikTokDataClient($config);
        $token = $this->vault->decrypt($c['access_token_encrypted']);
        $query = ['shop_cipher' => $c['external_shop_cipher']];
        $snapshot = json_decode($order['transaction_detail_json'], true) ?: [];
        $record = null;
        $estimated = false;
        if (in_array($snapshot['source_status'] ?? '', ['DELIVERED', 'COMPLETED'], true)) {
            $f = $this->readCall($client, $c, 'GET', '/finance/202501/orders/'.rawurlencode($orderId).'/statement_transactions', $query, $token);
            if (! empty($f['sku_transactions'])) {
                $record = $f;
            }
        }
        if (! $record) {
            $estimated = true;
            $from = strtotime($order['order_date']);
            $page = '';
            for ($i = 0; $i < 10; $i++) {
                $f = $this->readCall($client, $c, 'GET', '/finance/202507/orders/unsettled', $query + ['page_size' => 100, 'sort_field' => 'order_create_time', 'sort_order' => 'ASC', 'search_time_ge' => $from, 'search_time_lt' => $from + 1] + ($page ? ['page_token' => $page] : []), $token);
                foreach ($f['transactions'] ?? [] as $row) {
                    if (($row['type'] ?? '') === 'ORDER' && (string) ($row['order_id'] ?? '') === $orderId) {
                        $record = $row;
                        break;
                    }
                }if ($record) {
                    break;
                }
                $next = (string) ($f['next_page_token'] ?? '');
                if (! $next || $next === $page) {
                    break;
                }
                $page = $next;
            }
        }
        if (! $record) {
            return false;
        }
        $this->db()->beginTransaction();
        try {
            $this->connection($store, true);
            $this->saveRecord($store, ['type' => $estimated ? 'estimated_transaction' : 'transaction', 'id' => $orderId, 'data' => $record]);
            $this->db()->commit();

            return true;
        } catch (Throwable $e) {
            if (($this->db()->transactionLevel() > 0)) {
                $this->db()->rollBack();
            }throw $e;
        }
    }

    public function enqueueOrders(string $id, string $mode, array $filter, bool $excludeUnpaid): string
    {
        if (! $this->orderAutomation) {
            throw new DomainException('Jalur auto sync diperlukan.');
        }
        $this->db()->beginTransaction();
        try {
            $c = $this->connection($id, true);
            if ($this->value("SELECT run_key FROM marketplace_sync_jobs WHERE connection_id=? AND run_key LIKE 'auto-%' AND status IN ('queued','running') LIMIT 1", [$c['id']])) {
                throw new DomainException('Sinkronisasi toko masih berjalan.');
            }
            $run = 'auto-'.$mode.'-'.bin2hex(random_bytes(8));
            $planned = $this->plan($c, 'orders', $mode);
            if (empty($planned['sync_v2'])) {
                $planned = SyncWindow::normalize(['filter' => $filter], SyncWindow::forStore($this->db(), $id));
            }
            $planned['exclude_unpaid'] = $excludeUnpaid;
            $cp = json_encode($planned, JSON_THROW_ON_ERROR);
            $this->run("INSERT INTO marketplace_sync_jobs(connection_id,company_key,store_id,platform,job_type,run_key,status,checkpoint) VALUES (?,?,?,?,'orders',?,'queued',?)", [$c['id'], $c['company_key'], $id, $c['platform'], $run, $cp]);
            $this->db()->commit();

            return $run;
        } catch (Throwable $e) {
            if (($this->db()->transactionLevel() > 0)) {
                $this->db()->rollBack();
            }throw $e;
        }
    }

    public function enqueueAll(string $id): array
    {
        $this->db()->beginTransaction();
        try {
            $c = $this->connection($id, true);
            $active = $this->rows("SELECT run_key,job_type FROM marketplace_sync_jobs WHERE connection_id=? AND run_key NOT LIKE 'auto-%' AND status IN ('queued','running') ORDER BY id", [$c['id']]);
            $run = $active ? $active[0]['run_key'] : 'delta-'.bin2hex(random_bytes(12));
            $types = $this->column('SELECT job_type FROM marketplace_sync_jobs WHERE connection_id=? AND run_key=?', [$c['id'], $run]);
            foreach (($c['platform'] === 'Shopee' ? ['products', 'orders', 'transactions'] : self::TYPES) as $type) {
                if (! in_array($type, $types, true)) {
                    $this->run("INSERT INTO marketplace_sync_jobs(connection_id,company_key,store_id,platform,job_type,run_key,status,checkpoint) VALUES (?,?,?,?,?,?,'queued',?)", [$c['id'], $c['company_key'], $id, $c['platform'], $type, $run, json_encode($this->plan($c, $type), JSON_THROW_ON_ERROR)]);
                }
            }
            $this->db()->commit();

            return $this->status($id);
        } catch (Throwable $e) {
            if (($this->db()->transactionLevel() > 0)) {
                $this->db()->rollBack();
            }throw $e;
        }
    }

    public function status(string $id): array
    {
        $this->connection($id);
        $runFilter = $this->catalogOnly ? " AND run_key LIKE 'auto-catalog-%'" : ($this->connectedManual && ! $this->orderAutomation ? " AND run_key NOT LIKE 'auto-%'" : '');
        $jobs = $this->rows('SELECT id,job_type,run_key,status,success_count,failure_count,started_at,finished_at,last_error_code,checkpoint FROM marketplace_sync_jobs WHERE store_id=? AND company_key=? AND run_key=(SELECT run_key FROM marketplace_sync_jobs WHERE store_id=? AND company_key=?'.$runFilter.' ORDER BY id DESC LIMIT 1) ORDER BY id', [$id, $this->scope->companyKey(), $id, $this->scope->companyKey()]);
        foreach ($jobs as &$progress) {
            $cp = json_decode((string) ($progress['checkpoint'] ?? ''), true) ?: [];
            $progress['mode'] = $cp['mode'] ?? 'legacy';
            $progress['counts'] = $cp['counts'] ?? [];
            $progress['window_start'] = $cp['window_start'] ?? null;
            $progress['window_end'] = $cp['window_end'] ?? null;
            $progress['phase'] = ! empty($cp['not_due']) && $progress['status'] === 'succeeded' ? 'Menunggu rekonsiliasi' : ($progress['status'] === 'running' ? 'Mengambil' : ($progress['status'] === 'succeeded' ? 'Selesai' : ($progress['status'] === 'failed' ? 'Gagal' : 'Menunggu')));
            $progress['retry_at'] = $cp['_retry']['next_at'] ?? null;
            $progress['retry_attempt'] = $cp['_retry']['attempt'] ?? 0;
            $progress['error_category'] = $cp['_retry']['category'] ?? null;
            unset($progress['checkpoint']);
        }unset($progress);

        return ['jobs' => $jobs, 'returns' => $this->rows("SELECT external_id,source_updated_at FROM marketplace_sync_records WHERE store_id=? AND resource_type='return' ORDER BY fetched_at DESC LIMIT 100", [$id])];
    }

    public function step(string $id, ?string $runKey = null): array
    {
        $c = $this->connection($id);
        $db = (string) $this->value('SELECT DATABASE()');
        $locks = [];
        $take = function (string $key) use (&$locks): bool {
            if ((int) $this->value('SELECT GET_LOCK(?,0)', [$key]) !== 1) {
                return false;
            }
            $locks[] = $key;

            return true;
        };
        try {
            if (! $take('jl-read-store-'.sha1($db.':'.$c['company_key'].':'.$id))) {
                return $this->status($id);
            }
            $slots = max(1, min(8, (int) config('jago.sync.platform_concurrency.'.$c['platform'], 2)));
            $slot = false;
            for ($i = 0; $i < $slots; $i++) {
                if ($take('jl-read-platform-'.sha1($db.':'.$c['platform'].':'.$i))) {
                    $slot = true;
                    break;
                }
            }
            if (! $slot) {
                return $this->status($id);
            }

            return $this->processStep($id, $runKey);
        } finally {
            foreach (array_reverse($locks) as $key) {
                $this->run('SELECT RELEASE_LOCK(?)', [$key]);
            }
        }
    }

    private function processStep(string $id, ?string $runKey = null): array
    {
        $this->db()->beginTransaction();
        try {
            $c = $this->connection($id, true);
            $job = $this->row('SELECT * FROM marketplace_sync_jobs WHERE connection_id=? AND '.($runKey !== null ? 'run_key=? AND ' : '').($this->orderAutomation ? ($this->catalogOnly ? "run_key LIKE 'auto-catalog-%' AND " : "run_key LIKE 'auto-%' AND run_key NOT LIKE 'auto-catalog-%' AND ") : "run_key NOT LIKE 'auto-%' AND ")."(status='queued' OR (status='running' AND (lease_until IS NULL OR lease_until<NOW()))) AND COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(checkpoint,'$._retry.next_at')) AS UNSIGNED),0)<=UNIX_TIMESTAMP() ORDER BY id LIMIT 1 FOR UPDATE", $runKey !== null ? [$c['id'], $runKey] : [$c['id']]);
            if (! $job) {
                $this->db()->commit();

                return $this->status($id);
            }
            $pendingCheckpoint = json_decode((string) ($job['checkpoint'] ?? ''), true) ?: [];
            if ((int) ($pendingCheckpoint['_retry']['next_at'] ?? 0) > time()) {
                $this->db()->commit();

                return $this->status($id);
            }
            $this->readEvidence = [];
            $lease = bin2hex(random_bytes(16));
            $this->run("UPDATE marketplace_sync_jobs SET status='running',lease_token=?,lease_until=DATE_ADD(NOW(),INTERVAL 5 MINUTE),started_at=COALESCE(started_at,NOW()) WHERE id=?", [$lease, $job['id']]);
            $this->db()->commit();
            if (! empty($c['token_expires_at']) && strtotime($c['token_expires_at']) <= time() + 60) {
                $this->tokens->refreshConnection($id, false);
                $c = $this->connection($id);
            }
            if ($c['platform'] === 'TikTok Shop' && empty($c['external_shop_cipher'])) {
                throw new MarketplaceIntegrationException('Identitas cipher toko tidak tersedia.', 'SHOP_CIPHER_MISSING');
            }
            $config = $this->settings->configFor($c['platform']);
            $this->runtime->assertContext($c, $config);
            $client = $this->factory ? ($this->factory)($config) : ($c['platform'] === 'Shopee' ? new ShopeeReadAdapter($config, (string) $c['external_store_id']) : new TikTokDataClient($config));
            if ($this->orderAutomation && ! str_starts_with($job['run_key'], 'auto-')) {
                throw new MarketplaceIntegrationException('Job manual dilewati.', 'AUTO_JOB_ONLY');
            }
            $token = $this->vault->decrypt($c['access_token_encrypted']);
            $checkpoint = json_decode((string) ($job['checkpoint'] ?? ''), true) ?: [];
            if (empty($checkpoint['sync_v2'])) {
                $checkpoint = SyncWindow::normalize($checkpoint, SyncWindow::forStore($this->db(), $id));
            }
            $result = $this->fetchStep($client, $token, $c, $job, $checkpoint);
            // Recheck the lease and authorization before any source facts are written.
            $this->db()->beginTransaction();
            $this->connection($id, true);
            if (! $this->value("SELECT id FROM marketplace_sync_jobs WHERE id=? AND status='running' AND lease_token=? AND lease_until>NOW() FOR UPDATE", [$job['id'], $lease])) {
                throw new RuntimeException('Lease sinkronisasi telah berakhir.');
            }
            $count = 0;
            if ($result['done'] && in_array($job['job_type'], ['products', 'variants'], true) && ! empty($result['checkpoint']['active_catalog'])) {
                $seen = array_values(array_unique($result['checkpoint']['seen'] ?? []));
                $sql = 'UPDATE marketplace_catalog_variants SET catalog_visible=0 WHERE store_id=?'.($seen ? ' AND external_product_id NOT IN ('.implode(',', array_fill(0, count($seen), '?')).')' : '');
                $this->run($sql, array_merge([$id], $seen));
            }
            foreach ($result['records'] as $record) {
                $outcome = $this->saveRecord($id, $record);
                $result['checkpoint']['counts'][$outcome] = (int) ($result['checkpoint']['counts'][$outcome] ?? 0) + 1;
                $count++;
            }
            foreach ($result['checked'] ?? [] as $check) {
                $this->incremental->checked($id, $check[0], $check[1], $check[2]);
            }
            if ($result['done']) {
                $this->incremental->complete($result['checkpoint'], (int) $job['id']);
            }
            $runtime = $this->runtime;
            if ($this->readEvidence) {
                $runtime->bind($c, $config);
                foreach (array_keys($this->readEvidence) as $resource) {
                    $runtime->observe((int) $c['id'], $resource);
                }
            }
            foreach ($result['records'] as $record) {
                if ($record['type'] === 'product') {
                    foreach ($record['data']['skus'] ?? [] as $sku) {
                        if (isset($sku['price']['sale_price']) || isset($sku['price']['tax_exclusive_price'])) {
                            $runtime->observe((int) $c['id'], 'prices');
                        }if (StockService::quantities($sku)[''] !== null) {
                            $runtime->observe((int) $c['id'], 'stock');
                        }
                    }
                }
            }
            unset($result['checkpoint']['_retry']);
            $this->run('UPDATE marketplace_sync_jobs SET status=?,checkpoint=?,success_count=success_count+?,lease_token=NULL,lease_until=NULL,last_error_code=NULL,finished_at=IF(?=1,NOW(),NULL) WHERE id=? AND lease_token=?', [$result['done'] ? 'succeeded' : 'queued', json_encode($result['checkpoint'], JSON_THROW_ON_ERROR), $count, $result['done'] ? 1 : 0, $job['id'], $lease]);
            if ((int) $this->value("SELECT COUNT(*) FROM marketplace_sync_jobs WHERE connection_id=? AND run_key=? AND status<>'succeeded'", [$c['id'], $job['run_key']]) === 0) {
                $this->run('UPDATE marketplace_oauth_connections SET last_successful_sync_at=NOW(),last_sync_attempt_at=NOW(),last_error_code=NULL WHERE id=?', [$c['id']]);
            }
            $this->db()->commit();
        } catch (Throwable $error) {
            if (($this->db()->transactionLevel() > 0)) {
                $this->db()->rollBack();
            }
            if (isset($job['id'],$lease)) {
                $code = $error instanceof MarketplaceIntegrationException ? $error->errorCode : 'SYNC_WRITE_FAILED';
                $code = preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $code) ? $code : 'SYNC_FAILED';
                $cp = $checkpoint ?? (json_decode((string) ($job['checkpoint'] ?? ''), true) ?: []);
                $attempt = (int) ($cp['_retry']['attempt'] ?? 0);
                $delay = IntegrationPolicy::retry($code, $attempt, $error instanceof MarketplaceIntegrationException ? $error->retryAfterSeconds : null);
                $cp['_retry'] = ['attempt' => $attempt + 1, 'next_at' => $delay === null ? null : time() + $delay, 'category' => IntegrationPolicy::classify($code)];
                $this->run('UPDATE marketplace_sync_jobs SET status=?,checkpoint=?,failure_count=failure_count+1,last_error_code=?,lease_token=NULL,lease_until=NULL,finished_at=IF(?=1,NOW(),NULL) WHERE id=? AND lease_token=?', [$delay === null ? 'failed' : 'queued', json_encode($cp, JSON_THROW_ON_ERROR), $code, $delay === null ? 1 : 0, $job['id'], $lease]);
                $runtime = $this->runtime;
                $resource = match ($job['job_type']) {
                    'products','variants' => 'products','orders' => 'orders','transactions' => 'finance','returns' => 'returns',default => null
                };
                if ($resource !== null) {
                    $runtime->observe((int) $c['id'], $resource, $code);
                }
                if ($delay !== null && IntegrationPolicy::classify($code) === 'rate_limit' && isset($config)) {
                    $runtime->cooldown(IntegrationRuntime::scope($c['platform'], $config), $delay);
                }
            } else {
                throw $error;
            }
        }

        return $this->status($id);
    }

    private function readCall($client, array $c, string $method, string $path, array $query, string $token, ?array $body = null): array
    {
        $runtime = $this->runtime;
        $config = $this->settings->configFor($c['platform']);
        $runtime->assertContext($c, $config);
        $wait = $runtime->remainingCooldown(IntegrationRuntime::scope($c['platform'], $config));
        if ($wait > 0) {
            throw new MarketplaceIntegrationException('Menunggu batas permintaan platform.', 'HTTP_429', $wait);
        }
        $resource = str_starts_with($path, '/product/') ? 'products' : (str_starts_with($path, '/order/') ? 'orders' : (str_starts_with($path, '/finance/') || str_starts_with($path, '/payment/') || str_starts_with($path, '/api/v2/payment/') ? 'finance' : 'returns'));
        if ($client instanceof MarketplaceReadAdapter && str_starts_with($path, '/product/')) {
            $data = str_ends_with($path, '/search') ? $client->catalogPage($query, $token) : $client->product(rawurldecode(basename($path)), $query, $token);
        } elseif ($client instanceof MarketplaceReadAdapter && str_starts_with($path, '/order/')) {
            $data = str_ends_with($path, '/search') ? $client->orderPage($query, $token, $body ?? []) : $client->order((string) $query['ids'], $query, $token);
        } elseif ($client instanceof MarketplaceReadAdapter && (str_starts_with($path, '/payment/') || str_starts_with($path, '/api/v2/payment/'))) {
            $data = $client->escrowDetail((string) ($query['order_sn'] ?? $query['ids'] ?? $query['id'] ?? ''), $token);
        } else {
            $data = $client->call($method, $path, $query, $token, $body);
        }
        $this->readEvidence[$resource] = true;

        return $data;
    }

    public function retryFailed(string $store, int $jobId): array
    {
        $c = $this->connection($store);
        $this->db()->beginTransaction();
        try {
            $job = $this->row("SELECT checkpoint,run_key FROM marketplace_sync_jobs WHERE id=? AND connection_id=? AND company_key=? AND status='failed' FOR UPDATE", [$jobId, $c['id'], $this->scope->companyKey()]);
            if (! $job) {
                throw new DomainException('Proses gagal tidak ditemukan atau sudah dijalankan ulang.');
            }
            $cp = json_decode((string) $job['checkpoint'], true) ?: [];
            unset($cp['_retry']);
            $this->run("UPDATE marketplace_sync_jobs SET status='queued',checkpoint=?,last_error_code=NULL,finished_at=NULL,lease_until=NULL,lease_token=NULL WHERE id=?", [json_encode($cp, JSON_THROW_ON_ERROR), $jobId]);
            $this->db()->commit();

            return ['catalog' => str_starts_with($job['run_key'], 'auto-catalog-'), 'run_key' => $job['run_key']];
        } catch (Throwable $e) {
            if (($this->db()->transactionLevel() > 0)) {
                $this->db()->rollBack();
            }throw $e;
        }
    }

    private function plan(array $c, string $type, string $mode = ''): array
    {
        return $this->incremental->plan($c, $type, SyncWindow::forStore($this->db(), $c['store_id']), $mode);
    }

    private function eligibleOrder(string $store, array $order, array $cp): bool
    {
        $created = (int) ($order['create_time'] ?? 0);
        $start = (int) ($cp['history_start'] ?? $cp['window_start']);
        if ($created >= $start && $created <= $cp['window_end']) {
            return true;
        }
        if (empty($cp['sync_v2']) || ($cp['mode'] ?? '') === 'initial') {
            return false;
        }

        // The provider selected this order by update time. Its creation date is
        // irrelevant here, including when it predates the integration month.
        return $created > 0 && $created <= $cp['window_end'];
    }

    private function nextFinanceOrder(string $store, array $cp, bool $finalOnly = false): string|false
    {
        $start = date('Y-m-d H:i:s', (int) $cp['window_start']);
        $end = date('Y-m-d H:i:s', (int) $cp['window_end']);
        $where = "o.store_id=? AND o.source_kind='api' AND o.id>? AND ";
        $args = [$store, (string) ($cp['after'] ?? ''), $start, $end];
        $where .= '(o.order_date>=? AND o.order_date<=?';
        if (($cp['mode'] ?? '') === 'incremental') {
            $where .= " OR EXISTS (SELECT 1 FROM marketplace_sync_records r WHERE r.store_id=o.store_id AND r.resource_type='order' AND r.external_id=o.id AND r.source_updated_at>=? AND r.source_updated_at<=?)";
            $args[] = $start;
            $args[] = $end;
        }
        $where .= " OR JSON_EXTRACT(o.transaction_detail_json,'$.aftersale_finance_pending')=true)";
        if ($finalOnly) {
            $where .= " AND JSON_UNQUOTE(JSON_EXTRACT(o.transaction_detail_json,'$.source_status')) IN ('DELIVERED','COMPLETED') AND (COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.transaction_detail_json,'$.finance_schema')),'')<>'tiktok_202501' OR JSON_EXTRACT(o.transaction_detail_json,'$.is_estimated')=true OR JSON_EXTRACT(o.transaction_detail_json,'$.aftersale_finance_pending')=true)";
        }

        $id = $this->value('SELECT o.id FROM marketplace_orders o WHERE '.$where.' ORDER BY o.id LIMIT 1', $args);

        return $id === null ? false : (string) $id;
    }

    private function fetchStep($client, string $token, array $c, array $job, array $cp): array
    {
        $type = $job['job_type'];
        $q = ['shop_cipher' => $c['external_shop_cipher']];
        $records = [];
        $done = false;
        if (! empty($cp['not_due'])) {
            return ['records' => [], 'checkpoint' => $cp, 'done' => true];
        }
        if (in_array($type, ['products', 'variants'], true)) {
            if (empty($cp['active_catalog'])) {
                $cp['active_catalog'] = true;
                $cp['seen'] = [];
                $cp['pending'] = [];
                unset($cp['page_token']);
            }
            if (! empty($cp['pending'])) {
                $productId = array_shift($cp['pending']);
                $data = $this->readCall($client, $c, 'GET', '/product/202309/products/'.rawurlencode($productId), $q, $token);
                $records[] = ['type' => 'product', 'id' => $productId, 'data' => $data];
                if (in_array($data['status'] ?? '', ['ACTIVATE', 'NORMAL'], true)) {
                    $cp['seen'][] = $productId;
                }
                $done = ! $cp['pending'] && empty($cp['page_token']);
            } else {
                $data = $this->readCall($client, $c, 'POST', '/product/202309/products/search', $q + ['page_size' => 20] + (! empty($cp['page_token']) ? ['page_token' => $cp['page_token']] : []), $token, ['status' => 'ACTIVATE']);
                if (! isset($data['products']) && isset($data['total_count']) && (int) $data['total_count'] === 0) {
                    $data['products'] = [];
                }
                if (! isset($data['products']) || ! is_array($data['products'])) {
                    throw new MarketplaceIntegrationException('Daftar produk tidak lengkap.', 'PRODUCT_LIST_INVALID');
                }
                $cp['pending'] = array_map(static fn ($p) => (string) $p['id'], $data['products']);
                $next = (string) ($data['next_page_token'] ?? '');
                if ($next !== '' && $next === ($cp['page_token'] ?? null)) {
                    throw new MarketplaceIntegrationException('Token halaman berulang.', 'PAGINATION_LOOP');
                }
                $cp['page_token'] = $next;
                $done = ! $cp['pending'] && $next === '';
            }
        } elseif ($type === 'orders') {
            if (! empty($cp['pending'])) {
                $orderId = array_shift($cp['pending']);
                $data = $this->readCall($client, $c, 'GET', '/order/202309/orders', $q + ['ids' => $orderId], $token);
                if (! is_array($data['orders'] ?? null) || count($data['orders']) !== 1 || (string) $data['orders'][0]['id'] !== $orderId) {
                    throw new MarketplaceIntegrationException('Rincian pesanan tidak lengkap.', 'ORDER_DETAIL_INVALID');
                }
                $order = $data['orders'][0];
                if ($this->eligibleOrder($c['store_id'], $order, $cp) && (empty($cp['exclude_unpaid']) || ($order['status'] ?? '') !== 'UNPAID')) {
                    $records[] = ['type' => 'order', 'id' => $orderId, 'data' => $order];
                }
                $done = ! $cp['pending'] && empty($cp['page_token']);
            } else {
                $data = $this->readCall($client, $c, 'POST', '/order/202309/orders/search', $q + ['page_size' => 20, 'sort_order' => 'ASC', 'sort_field' => isset($cp['filter']['update_time_ge']) ? 'update_time' : 'create_time'] + (! empty($cp['page_token']) ? ['page_token' => $cp['page_token']] : []), $token, $cp['filter']);
                // TikTok omits orders on a successful zero-result search. Missing data without
                // explicit zero count (or with another page) remains an invalid response.
                if (! isset($data['orders']) && isset($data['total_count']) && is_numeric($data['total_count']) && (float) $data['total_count'] === 0.0 && empty($data['next_page_token'])) {
                    $data['orders'] = [];
                }
                if (! is_array($data['orders'] ?? null)) {
                    throw new MarketplaceIntegrationException('Daftar pesanan tidak lengkap.', 'ORDER_LIST_INVALID');
                }
                $cp['pending'] = array_map(static fn ($o) => (string) $o['id'], $data['orders']);
                $next = (string) ($data['next_page_token'] ?? '');
                if ($next !== '' && $next === ($cp['page_token'] ?? null)) {
                    throw new MarketplaceIntegrationException('Token halaman berulang.', 'PAGINATION_LOOP');
                }
                $cp['page_token'] = $next;
                $done = ! $cp['pending'] && $next === '';
            }
        } elseif ($type === 'transactions' && str_starts_with($job['run_key'], 'auto-finance-')) {
            if ($c['platform'] === 'Shopee') {
                $orderId = $this->nextFinanceOrder($c['store_id'], $cp);
                if ($orderId === false) {
                    $done = true;
                } else {
                    try {
                        $data = $this->readCall($client, $c, 'GET', '/api/v2/payment/get_escrow_detail', ['order_sn' => $orderId], $token);
                        if (! empty($data['order_income'])) {
                            $records[] = ['type' => 'shopee_escrow', 'id' => (string) $orderId, 'data' => $data];
                        }
                    } catch (Throwable $e) {
                        throw $e;
                    }
                    $cp['after'] = (string) $orderId;
                }
            } elseif (($cp['finance_stage'] ?? 'unsettled') === 'unsettled') {
                $data = $this->readCall($client, $c, 'GET', '/finance/202507/orders/unsettled', $q + ['page_size' => 100, 'sort_field' => 'order_create_time', 'sort_order' => 'ASC', 'search_time_ge' => $cp['window_start'], 'search_time_lt' => $cp['window_end'] + 1] + (! empty($cp['page_token']) ? ['page_token' => $cp['page_token']] : []), $token);
                if (! is_array($data['transactions'] ?? null)) {
                    throw new MarketplaceIntegrationException('Rincian estimasi tidak lengkap.', 'FINANCE_UNSETTLED_INVALID');
                }
                foreach ($data['transactions'] as $transaction) {
                    if (($transaction['type'] ?? '') !== 'ORDER' || empty($transaction['order_id'])) {
                        continue;
                    }
                    if ($this->value('SELECT COUNT(*) FROM marketplace_orders WHERE store_id=? AND id=?', [$c['store_id'], (string) $transaction['order_id']])) {
                        $records[] = ['type' => 'estimated_transaction', 'id' => (string) $transaction['order_id'], 'data' => $transaction];
                    }
                }
                $next = (string) ($data['next_page_token'] ?? '');
                if ($next !== '' && $next === ($cp['page_token'] ?? null)) {
                    throw new MarketplaceIntegrationException('Cursor berulang.', 'PAGINATION_LOOP');
                }
                $cp['page_token'] = $next;
                if ($next === '') {
                    $cp['finance_stage'] = 'statements';
                }
            } else {
                $order = $this->nextFinanceOrder($c['store_id'], $cp, true);
                if ($order === false) {
                    $done = true;
                } else {
                    $data = $this->readCall($client, $c, 'GET', '/finance/202501/orders/'.rawurlencode((string) $order).'/statement_transactions', $q, $token);
                    $records[] = ['type' => 'transaction', 'id' => (string) $order, 'data' => $data];
                    $cp['after'] = (string) $order;
                }
            }
        } elseif ($type === 'transactions') {
            $dependency = $this->value("SELECT status FROM marketplace_sync_jobs WHERE connection_id=? AND run_key=? AND job_type='orders'", [$c['id'], $job['run_key']]);
            if ($dependency === 'failed') {
                throw new MarketplaceIntegrationException('Sinkronisasi order harus berhasil dahulu.', 'SYNC_DEPENDENCY_FAILED');
            }
            if ($dependency !== null && $dependency !== 'succeeded') {
                return ['records' => [], 'checkpoint' => $cp, 'done' => false];
            }
            $orderId = $this->nextFinanceOrder($c['store_id'], $cp);
            if ($orderId === false) {
                $done = true;
            } else {
                if ($c['platform'] === 'Shopee') {
                    try {
                        $data = $this->readCall($client, $c, 'GET', '/api/v2/payment/get_escrow_detail', ['order_sn' => $orderId], $token);
                        if (! empty($data['order_income'])) {
                            $records[] = ['type' => 'shopee_escrow', 'id' => (string) $orderId, 'data' => $data];
                        }
                    } catch (Throwable $e) {
                        throw $e;
                    }
                    $cp['after'] = (string) $orderId;
                } else {
                    $data = $this->readCall($client, $c, 'GET', '/finance/202501/orders/'.rawurlencode((string) $orderId).'/statement_transactions', $q, $token);
                    $records[] = ['type' => 'transaction', 'id' => (string) $orderId, 'data' => $data];
                    $cp['after'] = (string) $orderId;
                }
            }
        } elseif ($type === 'returns') {
            $data = $this->readCall($client, $c, 'POST', '/return_refund/202602/returns/search', $q + ['page_size' => 20, 'sort_order' => 'ASC', 'sort_field' => 'update_time'] + (! empty($cp['page_token']) ? ['page_token' => $cp['page_token']] : []), $token, ['update_time_ge' => $cp['window_start'], 'update_time_lt' => $cp['window_end'] + 1]);
            $list = $data['return_orders'] ?? null;
            // TikTok Sandbox omits the list for a confirmed empty response.
            if ($list === null && isset($data['total_count']) && (int) $data['total_count'] === 0) {
                $list = [];
            }
            if (! is_array($list)) {
                throw new MarketplaceIntegrationException('Daftar retur tidak lengkap.', 'RETURN_LIST_INVALID');
            }
            foreach ($list as $row) {
                $created = (int) ($row['create_time'] ?? 0);
                $recent = ($cp['mode'] ?? '') === 'incremental' || ($created >= $cp['window_start'] && $created <= $cp['window_end']);
                if (! $recent && ! $created && ! empty($row['order_id'])) {
                    $recent = (bool) $this->value('SELECT COUNT(*) FROM marketplace_orders WHERE store_id=? AND id=? AND order_date>=? AND order_date<=?', [$c['store_id'], (string) $row['order_id'], date('Y-m-d H:i:s', $cp['window_start']), date('Y-m-d H:i:s', $cp['window_end'])]);
                }if ($recent) {
                    if (! empty($row['order_id'])) {
                        if (! $this->value('SELECT COUNT(*) FROM marketplace_orders WHERE store_id=? AND id=?', [$c['store_id'], (string) $row['order_id']])) {
                            $detail = $this->readCall($client, $c, 'GET', '/order/202309/orders', $q + ['ids' => (string) $row['order_id']], $token);
                            $parent = null;
                            foreach (($detail['orders'] ?? []) as $candidate) {
                                if ((string) ($candidate['id'] ?? '') === (string) $row['order_id']) {
                                    $parent = $candidate;
                                }
                            }
                            if (! $parent) {
                                throw new MarketplaceIntegrationException('Pesanan kasus belum tersedia.', 'RETURN_ORDER_UNAVAILABLE');
                            }
                            $records[] = ['type' => 'order', 'id' => (string) $row['order_id'], 'data' => $parent];
                        }
                    }
                    $records[] = ['type' => 'return', 'id' => (string) ($row['return_id'] ?? $row['id'] ?? ''), 'data' => $row];
                }
            }
            $next = (string) ($data['next_page_token'] ?? '');
            if ($next !== '' && $next === ($cp['page_token'] ?? null)) {
                throw new MarketplaceIntegrationException('Token halaman berulang.', 'PAGINATION_LOOP');
            }
            $cp['page_token'] = $next;
            $done = $next === '';
        } else {
            throw new MarketplaceIntegrationException('Jenis sinkronisasi belum tersedia.', 'SYNC_TYPE_UNSUPPORTED');
        }

        return ['records' => $records, 'checkpoint' => $cp, 'done' => $done];
    }

    private function saveRecord(string $storeId, array $record): string
    {
        if ($record['id'] === '') {
            throw new MarketplaceIntegrationException('ID sumber tidak tersedia.', 'SOURCE_ID_MISSING');
        }
        $data = $record['data'];
        $sourceTime = isset($data['update_time']) ? date('Y-m-d H:i:s', (int) $data['update_time']) : null;
        $old = $this->row('SELECT source_updated_at,payload_json FROM marketplace_sync_records WHERE store_id=? AND resource_type=? AND external_id=?', [$storeId, $record['type'], $record['id']]);
        if ($old && ! empty($old['source_updated_at']) && $sourceTime && $old['source_updated_at'] > $sourceTime) {
            return 'stale';
        }
        if ($old && IncrementalSync::digest(json_decode($old['payload_json'], true) ?: []) === IncrementalSync::digest($data)) {
            if ($record['type'] === 'transaction' || $record['type'] === 'shopee_escrow') {
                $this->saveFinance($storeId, $record['id'], $data);
            }

            return 'unchanged';
        }
        if ($record['type'] === 'product') {
            if (! in_array($data['status'] ?? '', ['ACTIVATE', 'NORMAL'], true)) {
                $this->run('UPDATE marketplace_catalog_variants SET catalog_visible=0,source_status=? WHERE store_id=? AND external_product_id=?', [$data['status'] ?? 'UNKNOWN', $storeId, $record['id']]);
            } else {
                $this->saveProduct($storeId, $data);
            }
        }
        if ($record['type'] === 'order') {
            $this->saveOrder($storeId, $data);
        }
        if ($record['type'] === 'estimated_transaction') {
            $this->saveFinance($storeId, $record['id'], $data, true);
        }
        if ($record['type'] === 'transaction' || $record['type'] === 'shopee_escrow') {
            $this->saveFinance($storeId, $record['id'], $data);
        }
        if ($record['type'] === 'return' && ! empty($data['order_id'])) {
            $goods = in_array($data['return_type'] ?? '', ['RETURN_AND_REFUND', 'REPLACEMENT', 'EXCHANGE'], true);
            $this->run("UPDATE marketplace_orders SET transaction_detail_json=JSON_SET(COALESCE(transaction_detail_json,'{}'),'$.has_aftersale',true,'$.aftersale_finance_pending',true) WHERE store_id=? AND id=? AND source_kind='api'", [$storeId, (string) $data['order_id']]);
            if ($goods) {
                $this->run("UPDATE marketplace_orders SET transaction_detail_json=JSON_SET(COALESCE(transaction_detail_json,'{}'),'$.has_return',true) WHERE store_id=? AND id=? AND source_kind='api'", [$storeId, (string) $data['order_id']]);
            }
        }
        $this->run('INSERT INTO marketplace_sync_records(store_id,resource_type,external_id,payload_json,source_updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json),source_updated_at=VALUES(source_updated_at),fetched_at=NOW()', [$storeId, $record['type'], $record['id'], json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $sourceTime]);
        if ($record['type'] === 'return' && ! empty($data['order_id'])) {
            $has = $this->value("SELECT COUNT(*) FROM marketplace_sync_records WHERE store_id=? AND resource_type='return' AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.order_id'))=? AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.return_type')) IN ('RETURN_AND_REFUND','REPLACEMENT','EXCHANGE') AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.return_status')),'') NOT IN ('REFUND_OR_RETURN_REQUEST_REJECT','RETURN_OR_REFUND_REQUEST_CANCEL','REPLACEMENT_REQUEST_REJECT','REPLACEMENT_REQUEST_CANCEL','EXCHANGE_REQUEST_CANCEL')", [$storeId, (string) $data['order_id']]) ? 'true' : 'false';
            $this->run("UPDATE marketplace_orders SET transaction_detail_json=JSON_SET(COALESCE(transaction_detail_json,'{}'),'$.has_return',JSON_EXTRACT(?,'$')) WHERE store_id=? AND id=? AND source_kind='api'", [$has, $storeId, (string) $data['order_id']]);
        }

        return $old ? 'updated' : 'new';
    }

    private function saveProduct(string $store, array $p): void
    {
        if (empty($p['id']) || ! isset($p['title']) || (! is_array($p['skus'] ?? null) || ! $p['skus'])) {
            throw new MarketplaceIntegrationException('Produk tidak lengkap.', 'PRODUCT_DETAIL_INVALID');
        }
        foreach ($p['skus'] as $sku) {
            $price = $sku['price'] ?? [];
            if (isset($price['currency']) && $price['currency'] !== 'IDR') {
                throw new MarketplaceIntegrationException('Mata uang selain IDR belum didukung.', 'CURRENCY_UNSUPPORTED');
            }
            $attrs = array_map(static fn ($v) => (string) ($v['value_name'] ?? ''), $sku['sales_attributes'] ?? []);
            $stock = StockService::quantities($sku)[''];
            $this->run('INSERT INTO marketplace_catalog_variants(store_id,external_product_id,external_variant_id,product_name,sku,variant_name,image_url,price,stock_qty,source_status,source_updated_at,parent_sku,original_price) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE product_name=VALUES(product_name),sku=VALUES(sku),variant_name=VALUES(variant_name),image_url=VALUES(image_url),price=VALUES(price),stock_qty=VALUES(stock_qty),source_status=VALUES(source_status),source_updated_at=VALUES(source_updated_at),catalog_visible=1,parent_sku=VALUES(parent_sku),original_price=COALESCE(VALUES(original_price),original_price)', [$store, (string) $p['id'], (string) $sku['id'], $p['title'], (string) ($sku['seller_sku'] ?? ''), implode(' / ', $attrs), $p['main_images'][0]['urls'][0] ?? null, $price['sale_price'] ?? $price['tax_exclusive_price'] ?? null, $stock, $p['status'] ?? null, date('Y-m-d H:i:s', (int) ($p['update_time'] ?? time())), $p['parent_sku'] ?? null, $price['original_price'] ?? null]);
        }
        $variantIds = array_map(static fn ($sku) => (string) $sku['id'], $p['skus']);
        $this->run('UPDATE marketplace_catalog_variants SET catalog_visible=0 WHERE store_id=? AND external_product_id=? AND external_variant_id NOT IN ('.implode(',', array_fill(0, count($variantIds), '?')).')', array_merge([$store, (string) $p['id']], $variantIds));
        StockService::capture($this->db(), $store, $p);
    }

    private function saveOrder(string $store, array $o): void
    {
        if (empty($o['id']) || empty($o['create_time']) || ! is_array($o['line_items'] ?? null)) {
            throw new MarketplaceIntegrationException('Pesanan tidak lengkap.', 'ORDER_DETAIL_INVALID');
        }
        $id = (string) $o['id'];
        $existing = $this->row('SELECT store_id,source_kind,transaction_detail_json FROM marketplace_orders WHERE id=? FOR UPDATE', [$id]);
        if ($existing && ($existing['store_id'] !== $store || $existing['source_kind'] !== 'api')) {
            throw new MarketplaceIntegrationException('ID pesanan dimiliki sumber lain.', 'ORDER_ID_CONFLICT');
        }
        $payment = $o['payment'] ?? [];
        if (isset($payment['currency']) && $payment['currency'] !== 'IDR') {
            throw new MarketplaceIntegrationException('Mata uang selain IDR belum didukung.', 'CURRENCY_UNSUPPORTED');
        }
        $gross = $payment['sub_total'] ?? $payment['total_amount'] ?? null;
        $snapshot = json_decode((string) ($existing['transaction_detail_json'] ?? ''), true) ?: [];
        $snapshot['source_status'] = $o['status'] ?? '';
        $snapshot['source_updated_at'] = date('Y-m-d H:i:s', (int) ($o['update_time'] ?? $o['create_time']));
        $snapshot['is_estimated'] = $snapshot['is_estimated'] ?? true;
        foreach (['is_buyer_request_cancel', 'cancellation_initiator', 'cancel_reason', 'order_type', 'delivery_status', 'is_sample_order', 'is_delivery_failed', 'is_lost'] as $field) {
            if (array_key_exists($field, $o)) {
                $snapshot[$field] = $o[$field];
            } else {
                unset($snapshot[$field]);
            }
        }
        if (isset($o['is_cod'])) {
            $snapshot['is_cod'] = (bool) $o['is_cod'];
        } else {
            unset($snapshot['is_cod']);
        }
        $snapshot['payment_method'] = $o['payment_method_name'] ?? '';
        $snapshot['tracking_number'] = $o['tracking_number'] ?? '';
        $snapshot['order_paid_amount'] = $gross === null ? null : (float) $gross;
        $snapshot['gross_amount'] = ! empty($snapshot['finance_available']) ? ($snapshot['finance_schema'] === 'tiktok_202507_unsettled' ? ($snapshot['finance_revenue'] ?? $gross) : ($snapshot['finance_order_gross'] ?? $gross)) : ($gross === null ? null : (float) $gross);
        $snapshot['items'] = [];
        $hpp = 0.0;
        $missingHpp = false;
        $grouped = [];
        foreach ($o['line_items'] as $line) {
            $key = (string) ($line['sku_id'] ?? $line['id'] ?? '');
            if ($key === '') {
                throw new MarketplaceIntegrationException('ID SKU pesanan kosong.', 'ORDER_SKU_MISSING');
            }if (! isset($grouped[$key])) {
                $grouped[$key] = ['line' => $line, 'qty' => 0];
            }
            $grouped[$key]['qty'] += (int) ($line['quantity'] ?? 1);
        }
        foreach ($grouped as $group) {
            $line = $group['line'];
            $sku = (string) ($line['seller_sku'] ?? '');
            $qty = $group['qty'];
            $unit = $this->value('SELECT unit_hpp FROM marketplace_hpp_history WHERE store_id=? AND marketplace_sku=? AND effective_from<=? ORDER BY effective_from DESC,id DESC LIMIT 1', [$store, $sku, date('Y-m-d', (int) $o['create_time'])]);
            $unit = $unit === null ? null : (float) $unit;
            if (isset($snapshot['hpp_overrides'][$sku])) {
                $unit = (float) $snapshot['hpp_overrides'][$sku];
            }
            $subtotal = $unit === null ? null : $unit * $qty;
            if ($unit === null) {
                $missingHpp = true;
            }
            $hpp += $subtotal ?? 0;
            $snapshot['items'][] = ['name' => $line['product_name'] ?? '', 'sku' => $sku, 'variant' => $line['sku_name'] ?? '', 'quantity' => $qty, 'returned_quantity' => null, 'unit_price' => $line['sale_price'] ?? null, 'total_price' => isset($line['sale_price']) ? (float) $line['sale_price'] * $qty : null, 'unit_cogs' => $unit, 'total_cogs' => $subtotal, 'image_url' => $line['sku_image'] ?? ''];
        }
        $snapshot['total_cogs'] = $missingHpp ? null : $hpp;
        if (! array_key_exists('net_income', $snapshot)) {
            $snapshot['net_income'] = null;
        }
        if (! array_key_exists('total_fees', $snapshot)) {
            $snapshot['total_fees'] = null;
        }
        $status = match ($o['status'] ?? '') {
            'COMPLETED' => 'Selesai','CANCELLED' => 'Dibatalkan','SHIPPED','IN_TRANSIT','DELIVERED','PARTIALLY_SHIPPING' => 'Terkirim',default => 'Pending'
        };
        $this->run("INSERT INTO marketplace_orders(id,store_id,order_date,order_status,gross_sales,total_cogs_hpp,source_kind,transaction_detail_json) VALUES (?,?,?,?,?,?,'api',?) ON DUPLICATE KEY UPDATE order_status=VALUES(order_status),gross_sales=VALUES(gross_sales),total_cogs_hpp=VALUES(total_cogs_hpp),transaction_detail_json=VALUES(transaction_detail_json)", [$id, $store, date('Y-m-d H:i:s', (int) $o['create_time']), $status, $gross ?? 0, $hpp, json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
        $this->run('DELETE FROM marketplace_order_items WHERE order_id=?', [$id]);
        foreach ($snapshot['items'] as $line) {
            $this->run('INSERT INTO marketplace_order_items(order_id,sku,item_name,qty,selling_price,unit_cogs_hpp,subtotal_price,subtotal_cogs) VALUES (?,?,?,?,?,?,?,?)', [$id, $line['sku'], $line['name'], $line['quantity'], $line['unit_price'] ?? 0, $line['unit_cogs'] ?? 0, $line['total_price'] ?? 0, $line['total_cogs'] ?? 0]);
        }
    }

    private function saveFinance(string $store, string $id, array $f, bool $estimated = false): void
    {
        if (isset($f['currency']) && $f['currency'] !== 'IDR') {
            throw new MarketplaceIntegrationException('Mata uang selain IDR belum didukung.', 'CURRENCY_UNSUPPORTED');
        }
        if (isset($f['order_income'])) {
            if (! empty($f['order_sn']) && (string) $f['order_sn'] !== $id) {
                throw new MarketplaceIntegrationException('Finance tidak cocok dengan pesanan.', 'FINANCE_ORDER_MISMATCH');
            }
            $order = $this->row('SELECT transaction_detail_json FROM marketplace_orders WHERE id=? AND store_id=? FOR UPDATE', [$id, $store]);
            if ($order === null) {
                return;
            }
            $snapshot = json_decode((string) $order['transaction_detail_json'], true) ?: [];
            $snapshot = FinanceNormalizer::applyShopeeEscrow($snapshot, $f);
            $this->run('UPDATE marketplace_orders SET net_settlement_amount=?,other_deductions=0,transaction_detail_json=? WHERE id=? AND store_id=?', [$snapshot['net_income'], json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $id, $store]);

            return;
        }
        if ((string) ($f['order_id'] ?? '') !== $id) {
            throw new MarketplaceIntegrationException('Finance tidak cocok dengan pesanan.', 'FINANCE_ORDER_MISMATCH');
        }
        if (! isset($f[$estimated ? 'est_settlement_amount' : 'settlement_amount'])) {
            return;
        }
        $snapshot = json_decode((string) $this->value('SELECT transaction_detail_json FROM marketplace_orders WHERE id=? AND store_id=? FOR UPDATE', [$id, $store]), true) ?: [];
        $snapshot = $estimated ? FinanceNormalizer::applyUnsettled($snapshot, $f) : FinanceNormalizer::apply($snapshot, $f);
        if (! $estimated && ! empty($f['sku_transactions'])) {
            $snapshot['is_estimated'] = false;
        }
        if (! $estimated) {
            $snapshot['aftersale_finance_pending'] = false;
        }
        $snapshot['finance_updated_at'] = date('Y-m-d H:i:s');
        // Root totals and available fee components are kept separately; do not invent fee categories.
        $this->run('UPDATE marketplace_orders SET net_settlement_amount=?,other_deductions=0,transaction_detail_json=? WHERE id=? AND store_id=?', [$snapshot['net_income'], json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $id, $store]);
    }
}
