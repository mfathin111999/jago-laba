<?php

namespace App\Services\Marketplace;

use App\Marketplace\IntegrationPolicy;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;

/**
 * Per-store integration health: connection state plus evidence for each API resource.
 * Exposes evidence and machine codes only, never credentials or source payloads. Reads local data; no provider call.
 *
 * The legacy "application context changed" check (config fingerprint vs. stored binding) is intentionally not
 * applied here: legacy fingerprints included dashboard-saved partner settings that Laravel does not read, so the
 * comparison would wrongly demand re-authorization. It returns with the OAuth binding port.
 */
final class MarketplaceIntegrationHealthService
{
    private const STATE_LABELS = [
        'available' => 'Tersedia', 'unverified' => 'Belum diverifikasi', 'permission' => 'Memerlukan izin',
        'unsupported' => 'Tidak didukung konektor', 'temporary' => 'Gagal sementara', 'reauthorize' => 'Perlu otorisasi ulang',
    ];

    private const SYNC_LABELS = [
        'not_started' => 'Belum dijalankan', 'queued' => 'Dalam antrean', 'running' => 'Sedang diproses',
        'retry_wait' => 'Menunggu percobaan ulang', 'succeeded' => 'Proses selesai', 'failed' => 'Proses gagal',
    ];

    private const SOURCE_MAP = ['products' => 'product', 'prices' => 'product', 'stock' => 'product', 'orders' => 'order', 'finance' => 'transaction', 'returns' => 'return'];

    public function __construct(private readonly DatabaseManager $database) {}

    /**
     * @return array<string, mixed>
     */
    public function forStore(string $companyKey, string $storeId, bool $canManage): array
    {
        $db = $this->database->connection();
        $store = $db->table('marketplace_stores')->where('id', $storeId)->where('company_key', $companyKey)->first(['platform', 'store_name']);
        if ($store === null) {
            throw new DomainException('Store tidak diizinkan.');
        }

        $connection = $db->table('marketplace_oauth_connections')->where('store_id', $storeId)->where('company_key', $companyKey)
            ->first(['id', 'platform', 'status', 'authorized_at', 'token_expires_at', 'last_successful_sync_at']);
        $connectionStatus = $connection->status ?? 'missing';
        $evidence = $this->evidence($connection);
        $binding = $connection && Schema::hasTable('marketplace_integration_bindings')
            ? $db->table('marketplace_integration_bindings')->where('connection_id', $connection->id)->first(['environment']) : null;
        $jobs = $this->latestJobs($companyKey, $storeId);
        $sourceTimes = Schema::hasTable('marketplace_sync_records')
            ? $db->table('marketplace_sync_records')->where('store_id', $storeId)->selectRaw('resource_type, MAX(fetched_at) as verified_at')->groupBy('resource_type')->pluck('verified_at', 'resource_type')->all() : [];
        $counts = $this->catalogCounts($storeId);

        $resources = [];
        foreach (IntegrationPolicy::resources($store->platform) as $key => $definition) {
            $resources[] = $this->resource($key, $definition, $connection, $connectionStatus, $evidence[$key] ?? null, $sourceTimes, $counts, $jobs);
        }
        $resources = $this->applyShippingEvidence($resources, $store->platform, $companyKey, $storeId, $connection);

        $environment = $binding->environment ?? 'unspecified';

        return [
            'store_id' => $storeId,
            'platform' => $store->platform,
            'store_name' => $store->store_name,
            'connection_status' => $connectionStatus,
            'connection_label' => $connectionStatus === 'reauthorize' ? 'Perlu Otorisasi Ulang' : match ($connectionStatus) {
                'verified' => 'Terhubung', 'disconnected' => 'Terputus', default => 'Belum terhubung',
            },
            'environment' => $environment,
            'environment_label' => match ($environment) {
                'live' => 'Live', 'sandbox' => 'Sandbox', 'development' => 'Development Shop', default => 'Lingkungan belum diverifikasi',
            },
            'token_expired' => $connection && ! empty($connection->token_expires_at) && strtotime((string) $connection->token_expires_at) <= time(),
            'last_successful_sync_at' => $connection->last_successful_sync_at ?? null,
            'counts' => array_map('intval', $counts),
            'resources' => $resources,
            'webhook_active' => false,
            'auto_driver' => 'browser-pulse',
            'manual_driver' => 'server-worker',
            'metadata_ready' => Schema::hasTable('marketplace_integration_bindings'),
            'can_manage' => $canManage,
        ];
    }

    /**
     * @param  array{label: string, job_types: list<string>, supported: bool}  $definition
     * @param  array<string, mixed>|null  $proof
     * @param  array<string, mixed>  $sourceTimes
     * @param  array<string, mixed>  $counts
     * @param  list<object>  $jobs
     * @return array<string, mixed>
     */
    private function resource(string $key, array $definition, ?object $connection, string $connectionStatus, ?array $proof, array $sourceTimes, array $counts, array $jobs): array
    {
        $state = $definition['supported'] ? 'unverified' : 'unsupported';
        $verified = $attempt = $error = $latest = null;

        if ($definition['supported']) {
            if ($proof) {
                [$state, $verified, $attempt, $error] = [$proof['state'], $proof['last_verified_at'], $proof['last_attempt_at'], $proof['last_error_code']];
            } else {
                $date = $sourceTimes[self::SOURCE_MAP[$key] ?? ''] ?? null;
                // Old data does not prove that newly granted tokens still have access.
                if ($date && $connection && $date >= $connection->authorized_at && (! in_array($key, ['prices', 'stock'], true) || (int) ($counts[$key] ?? 0) > 0)) {
                    $state = 'available';
                    $verified = $date;
                }
            }
            foreach ($jobs as $job) {
                if (in_array($job->job_type, $definition['job_types'], true) && (! $latest || (int) $job->id > (int) $latest->id)) {
                    $latest = $job;
                }
            }
            if ($latest && $latest->status === 'failed' && (! $connection || $latest->requested_at >= $connection->authorized_at)) {
                $error = $latest->last_error_code;
                $state = match (IntegrationPolicy::classify((string) $error)) {
                    'authorization' => 'reauthorize', 'permission' => 'permission', 'unsupported' => 'unsupported', default => 'temporary',
                };
            }
            if ($connectionStatus !== 'verified') {
                $state = 'reauthorize';
            }
        }

        $checkpoint = $latest ? (json_decode((string) ($latest->checkpoint ?? ''), true) ?: []) : [];
        $sync = $latest->status ?? 'not_started';
        $retryAt = $checkpoint['_retry']['next_at'] ?? null;
        if ($sync === 'queued' && $retryAt && $retryAt > time()) {
            $sync = 'retry_wait';
        }

        return [
            'key' => $key, 'label' => $definition['label'], 'state' => $state, 'state_label' => self::STATE_LABELS[$state] ?? 'Belum diverifikasi',
            'last_verified_at' => $verified, 'last_attempt_at' => $attempt, 'error_code' => $error,
            'sync_status' => $sync, 'sync_label' => self::SYNC_LABELS[$sync] ?? 'Belum diketahui',
            'processed' => $latest ? (int) $latest->success_count : 0, 'retry_at' => $retryAt, 'retry_attempt' => (int) ($checkpoint['_retry']['attempt'] ?? 0),
            'retry_job_id' => $latest && $latest->status === 'failed' ? (int) $latest->id : null,
        ];
    }

    /**
     * A successful shipping document is independent from successful order reads.
     *
     * @param  list<array<string, mixed>>  $resources
     * @return list<array<string, mixed>>
     */
    private function applyShippingEvidence(array $resources, string $platform, string $companyKey, string $storeId, ?object $connection): array
    {
        if ($platform !== 'TikTok Shop' || ! $connection || ! Schema::hasTable('marketplace_shipping_document_audit')) {
            return $resources;
        }
        $document = $this->database->connection()->table('marketplace_shipping_document_audit')
            ->where('store_id', $storeId)->where('company_key', $companyKey)->whereIn('event_type', ['prepared', 'cache_reused', 'failed'])
            ->orderByDesc('id')->first(['event_type', 'error_code', 'created_at']);
        if (! $document || $document->created_at < $connection->authorized_at) {
            return $resources;
        }

        foreach ($resources as &$resource) {
            if ($resource['key'] !== 'shipping' || $resource['state'] === 'reauthorize') {
                continue;
            }
            $resource['state'] = $document->event_type === 'failed'
                ? (IntegrationPolicy::classify((string) $document->error_code) === 'permission' ? 'permission' : 'temporary')
                : 'available';
            $resource['state_label'] = match ($resource['state']) {
                'available' => 'Tersedia', 'permission' => 'Memerlukan izin', default => 'Gagal sementara',
            };
            $resource['error_code'] = $document->error_code;
            $resource['last_verified_at'] = $resource['state'] === 'available' ? $document->created_at : null;
        }
        unset($resource);

        return $resources;
    }

    /** @return array<string, array<string, mixed>> */
    private function evidence(?object $connection): array
    {
        if (! $connection || ! Schema::hasTable('marketplace_api_capabilities')) {
            return [];
        }

        return $this->database->connection()->table('marketplace_api_capabilities')->where('connection_id', $connection->id)
            ->get(['resource', 'state', 'last_verified_at', 'last_attempt_at', 'last_error_code'])
            ->mapWithKeys(fn ($row) => [(string) $row->resource => (array) $row])->all();
    }

    /** @return list<object> */
    private function latestJobs(string $companyKey, string $storeId): array
    {
        $db = $this->database->connection();
        $latest = $db->table('marketplace_sync_jobs')->where('store_id', $storeId)->where('company_key', $companyKey)
            ->selectRaw('job_type, MAX(id) as id')->groupBy('job_type');

        return $db->table('marketplace_sync_jobs as j')->joinSub($latest, 'latest', 'latest.id', '=', 'j.id')->select('j.*')->get()->all();
    }

    /** @return array<string, int|string|null> */
    private function catalogCounts(string $storeId): array
    {
        $row = $this->database->connection()->table('marketplace_catalog_variants')
            ->where('store_id', $storeId)->where('catalog_visible', 1)->whereIn('source_status', ['ACTIVATE', 'NORMAL', 'ACTIVE', 'AKTIF'])
            ->selectRaw('COUNT(DISTINCT external_product_id) as products, COUNT(*) as variants, SUM(CASE WHEN price IS NOT NULL THEN 1 ELSE 0 END) as prices, SUM(CASE WHEN stock_qty IS NOT NULL THEN 1 ELSE 0 END) as stock')
            ->first();

        return (array) $row;
    }
}
