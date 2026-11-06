<?php

namespace App\Services\Marketplace\Stock;

use App\Services\Marketplace\Concerns\RunsSql;
use App\Services\Marketplace\MarketplaceScope;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Throwable;

final class StockService
{
    use RunsSql;

    public function __construct(private readonly DatabaseManager $database, private readonly MarketplaceScope $scope) {}

    /** Null aggregate if any warehouse fact is missing/duplicated; never guess physical stock. */
    public static function quantities(array $sku): array
    {
        $result = ['' => null];
        $inventory = $sku['inventory'] ?? null;
        if (! is_array($inventory) || ! $inventory) {
            return $result;
        }
        $seen = [];
        $total = 0;
        $complete = true;
        foreach ($inventory as $item) {
            $warehouse = (string) ($item['warehouse_id'] ?? '');
            $qty = $item['quantity'] ?? null;
            $valid = is_numeric($qty) && is_finite((float) $qty) && (float) $qty >= 0;
            if ($warehouse === '' || isset($seen[$warehouse])) {
                $complete = false;
                if ($warehouse !== '') {
                    $result[$warehouse] = null;
                }
            } else {
                $result[$warehouse] = $valid ? (float) $qty : null;
                $seen[$warehouse] = true;
            }if (! $valid) {
                $complete = false;
            } else {
                $total += (float) $qty;
            }
        }
        if (count($inventory) === 1 && empty($inventory[0]['warehouse_id']) && isset($inventory[0]['quantity']) && is_numeric($inventory[0]['quantity']) && is_finite((float) $inventory[0]['quantity']) && (float) $inventory[0]['quantity'] >= 0) {
            $complete = true;
        }
        $result[''] = $complete ? $total : null;

        return $result;
    }

    public static function capture(Connection $db, string $store, array $product): void
    {
        // Older isolated test schemas can run core sync without the optional stock-history migration.
        if (! $db->getSchemaBuilder()->hasTable('marketplace_stock_snapshots')) {
            return;
        }
        foreach ($product['skus'] ?? [] as $sku) {
            foreach (self::quantities($sku) as $warehouse => $qty) {
                $old = $db->selectOne('SELECT quantity,observed_at FROM marketplace_stock_snapshots WHERE store_id=? AND product_id=? AND variant_id=? AND warehouse_id=? ORDER BY observed_at DESC,id DESC LIMIT 1', [$store, (string) $product['id'], (string) $sku['id'], (string) $warehouse]);
                $old = $old === null ? null : (array) $old;
                if ($old && substr($old['observed_at'], 0, 10) === date('Y-m-d') && (($old['quantity'] === null && $qty === null) || ($old['quantity'] !== null && $qty !== null && (float) $old['quantity'] === $qty))) {
                    continue;
                }
                $db->statement('INSERT INTO marketplace_stock_snapshots(store_id,product_id,variant_id,warehouse_id,quantity,observed_at) VALUES (?,?,?,?,?,NOW())', [$store, (string) $product['id'], (string) $sku['id'], (string) $warehouse, $qty]);
            }
        }
    }

    public function listing(string $store, array $filters = []): array
    {
        $this->scope->assertStoreAccess($store);
        $start = $filters['date_start'] ?? date('Y-m-01');
        $end = $filters['date_end'] ?? date('Y-m-d');
        foreach ([$start, $end] as $v) {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            if (! $d || $d->format('Y-m-d') !== $v) {
                throw new InvalidArgumentException('Tanggal stok tidak valid.');
            }
        }if ($start > $end) {
            throw new InvalidArgumentException('Tanggal akhir mendahului awal.');
        }
        $warehouse = (string) ($filters['warehouse'] ?? '');
        $catalog = $this->rows("SELECT v.*,r.payload_json,r.fetched_at FROM marketplace_catalog_variants v LEFT JOIN marketplace_sync_records r ON r.store_id=v.store_id AND r.resource_type='product' AND r.external_id=v.external_product_id WHERE v.store_id=? AND v.catalog_visible=1 AND UPPER(COALESCE(v.source_status,'')) IN ('ACTIVATE','NORMAL','AKTIF','ACTIVE') ORDER BY v.product_name,v.variant_name", [$store]);
        $identityCounts = array_count_values(array_column($catalog, 'external_variant_id'));
        $sold = [];
        $knownOrders = false;
        foreach ($this->column("SELECT r.payload_json FROM marketplace_sync_records r JOIN marketplace_orders o ON o.store_id=r.store_id AND o.id=r.external_id WHERE r.store_id=? AND r.resource_type='order' AND o.source_kind='api' AND (o.internal_state IS NULL OR o.internal_state='active') AND o.order_date>=? AND o.order_date<?", [$store, $start, (new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d')]) as $json) {
            $o = json_decode($json, true) ?: [];
            $knownOrders = true;
            if (! in_array($o['status'] ?? '', ['AWAITING_SHIPMENT', 'AWAITING_COLLECTION', 'PARTIALLY_SHIPPING', 'IN_TRANSIT', 'DELIVERED', 'COMPLETED', 'READY_TO_SHIP', 'PROCESSED', 'SHIPPED', 'TO_CONFIRM_RECEIVE'], true)) {
                continue;
            }foreach ($o['line_items'] ?? [] as $line) {
                $vid = (string) ($line['sku_id'] ?? '');
                if ($vid === '' || ! is_numeric($line['quantity'] ?? 1)) {
                    continue;
                }
                $sold[$vid] = ($sold[$vid] ?? 0) + (float) ($line['quantity'] ?? 1);
            }
        }
        $rows = [];
        $warehouses = [];
        $summary = ['sku' => 0, 'known_stock' => 0, 'unknown' => 0, 'low' => 0, 'out' => 0];
        foreach ($catalog as $v) {
            $payload = json_decode($v['payload_json'] ?? '', true) ?: [];
            $remote = null;
            foreach ($payload['skus'] ?? [] as $sku) {
                if ((string) ($sku['id'] ?? '') === $v['external_variant_id']) {
                    $remote = $sku;
                    break;
                }
            }
            $quantities = $remote ? self::quantities($remote) : ['' => $v['stock_qty'] === null ? null : (float) $v['stock_qty']];
            foreach (array_keys($quantities) as $wid) {
                if ($wid !== '') {
                    $warehouses[(string) $wid] = (string) $wid;
                }
            }
            if ($warehouse !== '' && ! array_key_exists($warehouse, $quantities)) {
                continue;
            }
            $qty = $quantities[$warehouse] ?? null;
            $min = $this->value('SELECT minimum_qty FROM marketplace_stock_minimums WHERE store_id=? AND product_id=? AND variant_id=? AND warehouse_id=?', [$store, $v['external_product_id'], $v['external_variant_id'], $warehouse]);
            $min = $min === null ? null : (float) $min;
            $initial = $this->value('SELECT quantity FROM marketplace_stock_snapshots WHERE store_id=? AND product_id=? AND variant_id=? AND warehouse_id=? AND observed_at<=? ORDER BY observed_at DESC,id DESC LIMIT 1', [$store, $v['external_product_id'], $v['external_variant_id'], $warehouse, $start.' 00:00:00']);
            $initial = $initial === false || $initial === null ? null : (float) $initial;
            $state = $qty === null ? 'unknown' : ($qty <= 0 ? 'out' : ($min === null ? 'unconfigured' : ($qty <= $min ? 'low' : 'safe')));
            $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
            if ($search !== '' && ! str_contains(mb_strtolower($v['product_name'].' '.$v['variant_name'].' '.$v['sku']), $search)) {
                continue;
            }if (! empty($filters['status']) && $filters['status'] !== $state) {
                continue;
            }
            $rows[] = array_merge($v, ['current' => $qty, 'minimum' => $min, 'initial' => $initial, 'sold' => $warehouse === '' && $knownOrders && ($identityCounts[$v['external_variant_id']] ?? 0) === 1 ? ($sold[$v['external_variant_id']] ?? 0) : null, 'returned' => null, 'stock_status' => $state, 'warehouse' => $warehouse]);
            $summary['sku']++;
            if ($qty === null) {
                $summary['unknown']++;
            } else {
                $summary['known_stock'] += $qty;
            }if ($state === 'low') {
                $summary['low']++;
            }if ($state === 'out') {
                $summary['out']++;
            }
        }
        $sort = $filters['sort'] ?? 'name';
        usort($rows, static fn ($a, $b) => $sort === 'stock' ? (($a['current'] ?? INF) <=> ($b['current'] ?? INF)) : ($sort === 'sold' ? (($b['sold'] ?? -1) <=> ($a['sold'] ?? -1)) : strnatcasecmp($a['product_name'].' '.$a['variant_name'], $b['product_name'].' '.$b['variant_name'])));

        return ['rows' => $rows, 'summary' => $summary, 'warehouses' => $warehouses, 'date_start' => $start, 'date_end' => $end, 'warehouse' => $warehouse];
    }

    public function minimum(string $store, string $product, string $variant, string $warehouse, string $amount): void
    {
        $scope = $this->scope;
        $scope->assertStoreAccess($store);
        if (! preg_match('/^\d{1,10}(?:\.\d{1,3})?$/', $amount)) {
            throw new InvalidArgumentException('Minimum stok harus nol atau angka positif, maksimal 3 desimal.');
        }
        if (! $this->value('SELECT id FROM marketplace_catalog_variants WHERE store_id=? AND external_product_id=? AND external_variant_id=? AND catalog_visible=1', [$store, $product, $variant])) {
            throw new DomainException('Varian aktif tidak ditemukan di toko ini.');
        }
        if ($warehouse !== '') {
            $p = json_decode((string) $this->value("SELECT payload_json FROM marketplace_sync_records WHERE store_id=? AND resource_type='product' AND external_id=?", [$store, $product]), true) ?: [];
            $valid = false;
            foreach ($p['skus'] ?? [] as $s) {
                if ((string) ($s['id'] ?? '') === $variant) {
                    $valid = array_key_exists($warehouse, self::quantities($s));
                }
            }if (! $valid) {
                throw new DomainException('Gudang tidak cocok dengan varian toko.');
            }
        }
        $this->db()->beginTransaction();
        try {
            $this->run('SELECT id FROM marketplace_catalog_variants WHERE store_id=? AND external_product_id=? AND external_variant_id=? FOR UPDATE', [$store, $product, $variant]);
            $old = $this->value('SELECT minimum_qty FROM marketplace_stock_minimums WHERE store_id=? AND product_id=? AND variant_id=? AND warehouse_id=?', [$store, $product, $variant, $warehouse]);
            $actor = (string) ($this->scope->userId());
            $this->run('INSERT INTO marketplace_stock_minimums(store_id,product_id,variant_id,warehouse_id,minimum_qty,changed_by,changed_at) VALUES (?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE minimum_qty=VALUES(minimum_qty),changed_by=VALUES(changed_by),changed_at=NOW()', [$store, $product, $variant, $warehouse, $amount, $actor]);
            $this->run('INSERT INTO marketplace_stock_minimum_audit(company_key,store_id,product_id,variant_id,warehouse_id,previous_qty,new_qty,actor,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())', [$scope->companyKey(), $store, $product, $variant, $warehouse, $old === false ? null : $old, $amount, $actor]);
            $this->db()->commit();
        } catch (Throwable $e) {
            $this->db()->rollBack();
            throw $e;
        }
    }
}
