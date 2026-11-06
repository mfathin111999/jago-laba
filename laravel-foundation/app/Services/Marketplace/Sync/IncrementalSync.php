<?php

namespace App\Services\Marketplace\Sync;

use App\Exceptions\MarketplaceIntegrationException;
use App\Marketplace\IntegrationPolicy;
use Illuminate\Database\DatabaseManager;
use PDO;

final class IncrementalSync
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function ready(): bool
    {
        return (bool) $this->database->connection()->getSchemaBuilder()->hasTable('marketplace_sync_checkpoints');
    }

    public function plan(array $c, string $type, array $anchor, string $mode = ''): array
    {
        if (! $this->ready()) {
            throw new MarketplaceIntegrationException('Skema checkpoint sinkronisasi belum dipasang.', 'CHECKPOINT_SCHEMA_MISSING');
        }
        $dataset = $type === 'transactions' ? 'finance' : ($type === 'orders' && $mode === 'unpaid' ? 'orders_unpaid' : $type);
        $config = (new PartnerSettings($this->pdo()))->configFor($c['platform']);
        $environment = IntegrationPolicy::environment($c['platform'], $config);
        $scope = hash('sha256', implode('|', [$c['company_key'], $c['platform'], $environment, $c['store_id'], $c['external_store_id'], $dataset]));
        $this->pdo()->prepare('INSERT IGNORE INTO marketplace_sync_checkpoints(scope_key,company_key,store_id,platform,environment,dataset,history_start) VALUES (?,?,?,?,?,?,?)')->execute([$scope, $c['company_key'], $c['store_id'], $c['platform'], $environment, $dataset, $anchor['window_start']]);
        $q = $this->pdo()->prepare('SELECT * FROM marketplace_sync_checkpoints WHERE scope_key=? FOR UPDATE');
        $q->execute([$scope]);
        $saved = $q->fetch(PDO::FETCH_ASSOC);
        if (! $saved) {
            throw new MarketplaceIntegrationException('Identitas checkpoint berubah.', 'CHECKPOINT_CONTEXT_CONFLICT');
        }
        // Only completed legacy order/catalog ranges are eligible for adoption. Old finance jobs swallowed errors.
        if ($saved['completed_until'] === null && in_array($dataset, ['orders', 'products'], true)) {
            $q = $this->pdo()->prepare("SELECT checkpoint,id FROM marketplace_sync_jobs WHERE connection_id=? AND company_key=? AND job_type=? AND status='succeeded' AND run_key NOT LIKE 'auto-unpaid-%' ORDER BY finished_at DESC,id DESC LIMIT 1");
            $q->execute([$c['id'], $c['company_key'], $type]);
            $legacy = $q->fetch(PDO::FETCH_ASSOC);
            if ($legacy) {
                $old = json_decode($legacy['checkpoint'] ?? '', true) ?: [];
                if (! empty($old['window_end']) && empty($old['sync_v2'])) {
                    $saved['completed_until'] = (int) $old['window_end'];
                    $this->pdo()->prepare('UPDATE marketplace_sync_checkpoints SET completed_until=?,last_success_at=NOW(),last_job_id=? WHERE scope_key=?')->execute([$saved['completed_until'], $legacy['id'], $scope]);
                }
            }
        }
        $end = (int) $anchor['window_end'];
        $start = $saved['completed_until'] === null ? (int) $saved['history_start'] : max((int) $saved['history_start'], (int) $saved['completed_until'] - 300);
        $cp = ['sync_v2' => true, 'scope_key' => $scope, 'dataset' => $dataset, 'mode' => $saved['completed_until'] === null ? 'initial' : 'incremental', 'history_start' => (int) $saved['history_start'], 'window_start' => $start, 'window_end' => $end, 'counts' => ['new' => 0, 'updated' => 0, 'unchanged' => 0, 'stale' => 0, 'waiting' => 0], 'filter' => []];
        if ($type === 'orders') {
            $field = $cp['mode'] === 'initial' ? 'create_time' : 'update_time';
            $cp['filter'] = [$field.'_ge' => $start, $field.'_lt' => $end + 1];
            if ($mode === 'unpaid') {
                $cp['filter']['order_status'] = 'UNPAID';
            }
        }
        // Product search has no verified update-time filter in these connectors.
        // Reconcile the active catalog on a bounded schedule instead of on every click.
        $catalogInterval = (int) config('jago.integration.catalog_reconcile_seconds');
        if ($type === 'products' && $saved['completed_until'] !== null && $end - (int) $saved['completed_until'] < $catalogInterval) {
            $cp['not_due'] = true;
        }
        $returnsInterval = (int) config('jago.integration.returns_reconcile_seconds');
        if ($type === 'returns' && $saved['completed_until'] !== null && $end - (int) $saved['completed_until'] < $returnsInterval) {
            $cp['not_due'] = true;
        }

        return $cp;
    }

    public function complete(array $cp, int $job): void
    {
        if (empty($cp['scope_key']) || ! $this->ready()) {
            return;
        }
        if (empty($cp['not_due'])) {
            $this->pdo()->prepare('UPDATE marketplace_sync_checkpoints SET completed_until=GREATEST(COALESCE(completed_until,0),?),last_success_at=NOW(),last_job_id=? WHERE scope_key=?')->execute([$cp['window_end'], $job, $cp['scope_key']]);
        }
    }

    public function checked(string $store, string $resource, string $id, int $delay): void
    {
        $this->pdo()->prepare('INSERT INTO marketplace_sync_object_checks(store_id,resource_type,external_id,checked_at,next_check_at) VALUES (?,?,?,NOW(),DATE_ADD(NOW(),INTERVAL ? SECOND)) ON DUPLICATE KEY UPDATE checked_at=NOW(),next_check_at=VALUES(next_check_at)')->execute([$store, $resource, $id, $delay]);
    }

    public static function digest(array $data): string
    {
        $sort = function ($value) use (&$sort) {
            if (! is_array($value)) {
                return $value;
            }if (! array_is_list($value)) {
                ksort($value);
            }foreach ($value as &$v) {
                $v = $sort($v);
            }

            return $value;
        };

        return hash('sha256', json_encode($sort($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function pdo(): PDO
    {
        return $this->database->connection()->getPdo();
    }
}
