<?php

namespace App\Services\Marketplace\Catalog;

use App\Services\Marketplace\MarketplaceScope;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use PDO;
use Throwable;

final class CatalogService
{
    public function __construct(private readonly DatabaseManager $database, private readonly MarketplaceScope $scope) {}

    public function listingForStore(string $storeId, array $filters = []): array
    {
        $store = $this->scope->assertStoreAccess($storeId);

        return $this->listing(array_replace($filters, ['store_id' => $storeId, 'platform' => $store['platform'], 'official_only' => true]));
    }

    public function listing(array $filters = []): array
    {
        $ids = ! empty($filters['store_id']) ? [(string) $filters['store_id']] : $this->scope->accessibleStoreIds();
        $products = [];
        $totalSold = 0;
        $statuses = [];
        $officialOnly = true;
        foreach ($ids as $id) {
            $store = $this->scope->assertStoreAccess($id);
            if (! empty($filters['store_id']) && $filters['store_id'] !== $id) {
                continue;
            }
            if (! empty($filters['platform']) && $filters['platform'] !== $store['platform']) {
                continue;
            }
            $stmt = $this->pdo()->prepare("SELECT * FROM marketplace_catalog_variants WHERE store_id=? AND catalog_visible=1 AND UPPER(COALESCE(source_status,'')) IN ('ACTIVATE','NORMAL','AKTIF','ACTIVE') ORDER BY product_name,variant_name");
            $stmt->execute([$id]);
            $variants = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $known = array_fill_keys(array_column($variants, 'sku'), true);
            $stmt = $this->pdo()->prepare("SELECT oi.*, o.order_date FROM marketplace_order_items oi JOIN marketplace_orders o ON o.id=oi.order_id WHERE o.store_id=? AND (o.internal_state IS NULL OR o.internal_state='active') ORDER BY o.order_date DESC, oi.id DESC");
            $stmt->execute([$id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($officialOnly ? [] : $items as $item) {
                if ($item['sku'] === '' || isset($known[$item['sku']])) {
                    continue;
                }
                $known[$item['sku']] = true;
                $variants[] = ['external_product_id' => 'observed:'.$item['item_name'], 'product_name' => $item['item_name'], 'parent_sku' => null, 'sku' => $item['sku'], 'variant_name' => $item['sku'], 'price' => $item['selling_price'], 'original_price' => null, 'stock_qty' => null, 'source_status' => null, 'image_url' => '', 'source_updated_at' => $item['order_date'], 'observed' => true];
            }
            $stmt = $this->pdo()->prepare('SELECT marketplace_sku,marketplace_product_name FROM marketplace_sku_mappings WHERE store_id=?');
            $stmt->execute([$id]);
            $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($officialOnly ? [] : $mappings as $mapping) {
                if (isset($known[$mapping['marketplace_sku']])) {
                    continue;
                }
                $variants[] = ['external_product_id' => 'mapping:'.$mapping['marketplace_sku'], 'product_name' => $mapping['marketplace_product_name'], 'parent_sku' => null, 'sku' => $mapping['marketplace_sku'], 'variant_name' => $mapping['marketplace_sku'], 'price' => null, 'original_price' => null, 'stock_qty' => null, 'source_status' => null, 'image_url' => '', 'source_updated_at' => null, 'observed' => true];
            }
            $start = ! empty($filters['date_start']) ? $filters['date_start'] : (new DateTimeImmutable('today', new DateTimeZone('Asia/Jakarta')))->modify('-13 days')->format('Y-m-d');
            $end = ! empty($filters['date_end']) ? $filters['date_end'] : (new DateTimeImmutable('today', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
            $stmt = $this->pdo()->prepare("SELECT oi.sku,SUM(oi.qty) sold FROM marketplace_order_items oi JOIN marketplace_orders o ON o.id=oi.order_id WHERE o.store_id=? AND o.order_date>=? AND o.order_date<? AND o.order_status NOT IN ('Dibatalkan','Retur','CANCELLED') AND (o.internal_state IS NULL OR o.internal_state='active') GROUP BY oi.sku");
            $stmt->execute([$id, $start, (new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d')]);
            $sold = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $stmt = $this->pdo()->prepare('SELECT marketplace_sku,unit_hpp FROM marketplace_hpp_history WHERE store_id=? AND effective_from<=? ORDER BY effective_from DESC,id DESC');
            $stmt->execute([$id, (new DateTimeImmutable('today', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d')]);
            $hpp = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $history) {
                if (! array_key_exists($history['marketplace_sku'], $hpp)) {
                    $hpp[$history['marketplace_sku']] = (float) $history['unit_hpp'];
                }
            }
            foreach ($variants as $variant) {
                if (! empty($variant['source_status'])) {
                    $statuses[$variant['source_status']] = true;
                }
                $variant['hpp'] = $hpp[$variant['sku']] ?? null;
                if ($variant['hpp'] === null && ! $officialOnly) {
                    foreach ($items as $item) {
                        if ($item['sku'] === $variant['sku'] && $item['unit_cogs_hpp'] !== null) {
                            $variant['hpp'] = (float) $item['unit_cogs_hpp'];
                            break;
                        }
                    }
                }
                $variant['sold'] = (float) ($sold[$variant['sku']] ?? 0);
                if (! empty($filters['search']) && stripos($variant['product_name'].' '.$variant['sku'].' '.$variant['variant_name'], $filters['search']) === false) {
                    continue;
                }
                if (! empty($filters['product_status']) && ($variant['source_status'] ?? '') !== $filters['product_status']) {
                    continue;
                }
                $key = $id.':'.$variant['external_product_id'];
                if (! isset($products[$key])) {
                    $products[$key] = ['name' => $variant['product_name'], 'sku' => $variant['parent_sku'], 'store_id' => $id, 'store_name' => $store['store_name'], 'platform' => $store['platform'], 'variants' => [], 'sold' => 0];
                }
                $products[$key]['variants'][] = $variant;
                $products[$key]['sold'] += $variant['sold'];
                $totalSold += $variant['sold'];
            }
        }
        $products = array_values($products);
        usort($products, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return ['products' => $products, 'sold' => $totalSold, 'statuses' => array_keys($statuses), 'missing_hpp' => count(array_filter($products, static fn ($p) => count(array_filter($p['variants'], static fn ($v) => $v['hpp'] === null || $v['hpp'] <= 0)) > 0))];
    }

    /** Caller verifies the official response, then passes normalized catalog facts. */
    public function saveVariant(string $storeId, array $variant): void
    {
        $this->scope->assertStoreAccess($storeId);
        foreach (['external_product_id', 'external_variant_id', 'product_name', 'sku', 'source_updated_at'] as $key) {
            if (! isset($variant[$key]) || ! is_string($variant[$key]) || trim($variant[$key]) === '') {
                throw new InvalidArgumentException('Identitas katalog tidak lengkap.');
            }
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $variant['source_updated_at']);
        if (! $date || $date->format('Y-m-d H:i:s') !== $variant['source_updated_at']) {
            throw new InvalidArgumentException('Waktu katalog tidak valid.');
        }
        foreach (['price', 'original_price', 'stock_qty'] as $key) {
            if (isset($variant[$key]) && (! is_numeric($variant[$key]) || ! is_finite((float) $variant[$key]) || $variant[$key] < 0)) {
                throw new InvalidArgumentException('Harga atau stok katalog tidak valid.');
            }
        }
        $columns = ['external_product_id', 'external_variant_id', 'product_name', 'parent_sku', 'sku', 'variant_name', 'image_url', 'price', 'original_price', 'stock_qty', 'source_status', 'source_updated_at'];
        $this->pdo()->beginTransaction();
        try {
            $lock = $this->pdo()->prepare('SELECT id FROM marketplace_stores WHERE id=? FOR UPDATE');
            $lock->execute([$storeId]);
            $find = $this->pdo()->prepare('SELECT id,source_updated_at FROM marketplace_catalog_variants WHERE store_id=? AND external_product_id=? AND external_variant_id=? FOR UPDATE');
            $find->execute([$storeId, $variant['external_product_id'], $variant['external_variant_id']]);
            $existing = $find->fetch(PDO::FETCH_ASSOC);
            if ($existing && $existing['source_updated_at'] > $variant['source_updated_at']) {
                $this->pdo()->commit();

                return;
            }
            $values = array_map(static fn ($key) => $variant[$key] ?? null, $columns);
            if ($existing) {
                $stmt = $this->pdo()->prepare('UPDATE marketplace_catalog_variants SET '.implode(',', array_map(static fn ($key) => "$key=?", $columns)).' WHERE id=? AND store_id=?');
                $stmt->execute(array_merge($values, [$existing['id'], $storeId]));
            } else {
                $stmt = $this->pdo()->prepare('INSERT INTO marketplace_catalog_variants ('.implode(',', $columns).',store_id) VALUES ('.implode(',', array_fill(0, count($columns) + 1, '?')).')');
                $stmt->execute(array_merge($values, [$storeId]));
            }
            $this->pdo()->commit();
        } catch (Throwable $error) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }throw $error;
        }
    }

    /** Validate the entire draft before atomically updating any SKU or transaction. */
    public function updateHppBatch(array $changes): array
    {
        if (! $changes || count($changes) > 500) {
            throw new InvalidArgumentException('Pilih 1–500 SKU untuk disimpan.');
        }
        $validated = [];
        foreach ($changes as $change) {
            if (! is_array($change)) {
                throw new InvalidArgumentException('Format perubahan tidak valid.');
            }
            $store = (string) ($change['store_id'] ?? '');
            $sku = (string) ($change['sku'] ?? '');
            $amount = (string) ($change['hpp'] ?? '');
            $this->scope->assertStoreAccess($store);
            if ($sku === '' || ! preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $amount)) {
                throw new InvalidArgumentException('SKU atau HPP tidak valid.');
            }
            $key = $store."\0".$sku;
            if (isset($validated[$key])) {
                throw new InvalidArgumentException('SKU yang sama dikirim lebih dari sekali.');
            }
            $q = $this->pdo()->prepare("SELECT COUNT(*) FROM marketplace_catalog_variants WHERE store_id=? AND sku=? AND catalog_visible=1 AND UPPER(source_status) IN ('ACTIVATE','NORMAL','AKTIF','ACTIVE')");
            $q->execute([$store, $sku]);
            if (! $q->fetchColumn()) {
                throw new DomainException('SKU aktif tidak ditemukan di toko yang dipilih.');
            }
            $date = trim((string) ($change['effective_from'] ?? ''));
            $all = $date === '';
            if (! $all) {
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (! $parsed || $parsed->format('Y-m-d') !== $date) {
                    throw new InvalidArgumentException('Tanggal HPP tidak valid.');
                }
            }
            $validated[$key] = [$store, $sku, $amount, $all, $all ? null : $date];
        }
        ksort($validated);
        $this->pdo()->beginTransaction();
        try {
            $affected = 0;
            foreach ($validated as $args) {
                $affected += $this->updateHpp(...$args);
            }
            $this->pdo()->commit();

            return ['sku_count' => count($validated), 'affected_order_updates' => $affected];
        } catch (Throwable $e) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }throw $e;
        }
    }

    /** Explicit reconciliation of missing historical costs using configured current product HPP. */
    public function fillMissingOrderHpp(string $storeId): int
    {
        $this->scope->assertStoreAccess($storeId);
        $this->pdo()->beginTransaction();
        try {
            $lock = $this->pdo()->prepare('SELECT id FROM marketplace_stores WHERE id=? FOR UPDATE');
            $lock->execute([$storeId]);
            $q = $this->pdo()->prepare('SELECT marketplace_sku,unit_hpp FROM marketplace_hpp_history WHERE store_id=? AND effective_from<=CURDATE() ORDER BY effective_from DESC,id DESC');
            $q->execute([$storeId]);
            $costs = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (! array_key_exists($row['marketplace_sku'], $costs)) {
                    $costs[$row['marketplace_sku']] = (float) $row['unit_hpp'];
                }
            }
            $q = $this->pdo()->prepare("SELECT id,transaction_detail_json FROM marketplace_orders WHERE store_id=? AND source_kind='api' FOR UPDATE");
            $q->execute([$storeId]);
            $orders = $q->fetchAll(PDO::FETCH_ASSOC);
            $affected = 0;
            $audits = [];
            foreach ($orders as $order) {
                $snapshot = json_decode((string) $order['transaction_detail_json'], true);
                if (! is_array($snapshot) || ! is_array($snapshot['items'] ?? null)) {
                    continue;
                }$changed = false;
                foreach ($snapshot['items'] as &$item) {
                    $sku = (string) ($item['sku'] ?? '');
                    if (isset($item['unit_cogs']) || ! array_key_exists($sku, $costs)) {
                        continue;
                    }
                    $item['unit_cogs'] = $costs[$sku];
                    $item['total_cogs'] = max(0, (float) ($item['quantity'] ?? 0) - (float) ($item['returned_quantity'] ?? 0)) * $costs[$sku];
                    $snapshot['hpp_overrides'][$sku] = $costs[$sku];
                    $snapshot['hpp_override_sources'][$sku] = 'Pengisian HPP kosong dari pengaturan Produk saat ini';
                    $this->pdo()->prepare('UPDATE marketplace_order_items SET unit_cogs_hpp=?,subtotal_cogs=qty*? WHERE order_id=? AND sku=?')->execute([$costs[$sku], $costs[$sku], $order['id'], $sku]);
                    $audits[$sku] = ($audits[$sku] ?? 0) + 1;
                    $changed = true;
                }unset($item);
                if (! $changed) {
                    continue;
                }
                $total = count(array_filter($snapshot['items'], static fn ($item) => ! isset($item['total_cogs']))) ? null : array_sum(array_column($snapshot['items'], 'total_cogs'));
                $snapshot['total_cogs'] = $total;
                $this->pdo()->prepare('UPDATE marketplace_orders SET total_cogs_hpp=?,transaction_detail_json=? WHERE id=? AND store_id=?')->execute([$total ?? 0, json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $order['id'], $storeId]);
                $affected++;
            }
            foreach ($audits as $sku => $count) {
                $this->pdo()->prepare('INSERT INTO marketplace_hpp_changes(store_id,sku,old_hpp,new_hpp,changed_by,affected_orders) VALUES (?,?,NULL,?,?,?)')->execute([$storeId, $sku, $costs[$sku], substr((string) ($this->scope->userId()).':fill-missing', 0, 120), $count]);
            }
            $this->pdo()->commit();

            return $affected;
        } catch (Throwable $e) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }throw $e;
        }
    }

    /** Historical recomputation is an explicit opt-in; normal edits start on effectiveFrom. */
    public function updateProductHpp(string $storeId, string $productId, string $amount, bool $recalculateHistory = false, ?string $effectiveFrom = null): int
    {
        $this->scope->assertStoreAccess($storeId);
        $this->pdo()->beginTransaction();
        try {
            $stmt = $this->pdo()->prepare("SELECT DISTINCT sku FROM marketplace_catalog_variants WHERE store_id=? AND external_product_id=? AND catalog_visible=1 AND UPPER(source_status) IN ('ACTIVATE','NORMAL','AKTIF','ACTIVE') FOR UPDATE");
            $stmt->execute([$storeId, $productId]);
            $skus = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (! $skus || in_array('', $skus, true)) {
                throw new DomainException('SKU produk belum lengkap. Atur HPP per varian yang memiliki SKU.');
            }
            $affected = 0;
            foreach ($skus as $sku) {
                $affected += $this->updateHpp($storeId, $sku, $amount, $recalculateHistory, $effectiveFrom);
            }$this->pdo()->commit();

            return $affected;
        } catch (Throwable $e) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }throw $e;
        }
    }

    public function updateHpp(string $storeId, string $sku, string $amount, bool $recalculateHistory = false, ?string $effectiveFrom = null): int
    {
        $this->scope->assertStoreAccess($storeId);
        if ($sku === '' || ! preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $amount)) {
            throw new InvalidArgumentException('SKU atau HPP tidak valid.');
        }
        $effectiveFrom = $effectiveFrom ?: date('Y-m-d');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveFrom);
        if (! $date || $date->format('Y-m-d') !== $effectiveFrom) {
            throw new InvalidArgumentException('Tanggal mulai HPP tidak valid.');
        }
        $ownsTransaction = ! $this->pdo()->inTransaction();
        if ($ownsTransaction) {
            $this->pdo()->beginTransaction();
        }
        try {
            $lock = $this->pdo()->prepare('SELECT id FROM marketplace_stores WHERE id=? FOR UPDATE');
            $lock->execute([$storeId]);
            $check = $this->pdo()->prepare('SELECT sku FROM marketplace_catalog_variants WHERE store_id=? AND sku=? UNION SELECT oi.sku FROM marketplace_order_items oi JOIN marketplace_orders o ON o.id=oi.order_id WHERE o.store_id=? AND oi.sku=? UNION SELECT marketplace_sku FROM marketplace_sku_mappings WHERE store_id=? AND marketplace_sku=?');
            $check->execute([$storeId, $sku, $storeId, $sku, $storeId, $sku]);
            if ($check->fetchColumn() === false) {
                throw new DomainException('SKU tidak ditemukan di toko aktif.');
            }
            $old = $this->pdo()->prepare('SELECT unit_hpp FROM marketplace_hpp_history WHERE store_id=? AND marketplace_sku=? AND effective_from<=CURDATE() ORDER BY effective_from DESC,id DESC LIMIT 1');
            $old->execute([$storeId, $sku]);
            $oldHpp = $old->fetchColumn();
            $actor = (string) ($this->scope->username() ?? $this->scope->userId());
            $history = $this->pdo()->prepare("INSERT INTO marketplace_hpp_history(store_id,marketplace_sku,unit_hpp,effective_from,source,reason,changed_by) VALUES (?,?,?,?,'MANUAL',?,?) ON DUPLICATE KEY UPDATE unit_hpp=VALUES(unit_hpp),reason=VALUES(reason),changed_by=VALUES(changed_by)");
            $history->execute([$storeId, $sku, $amount, $effectiveFrom, $recalculateHistory ? 'Hitung ulang histori secara eksplisit' : 'Edit HPP berlaku mulai tanggal dipilih', $actor]);
            $orders = $this->pdo()->prepare('SELECT * FROM marketplace_orders WHERE store_id=? FOR UPDATE');
            $orders->execute([$storeId]);
            $affected = 0;
            foreach ($orders->fetchAll(PDO::FETCH_ASSOC) as $order) {
                $orderDate = substr((string) $order['order_date'], 0, 10);
                if (! $recalculateHistory && $orderDate < $effectiveFrom) {
                    continue;
                }
                $applyAmount = $amount;
                if (! $recalculateHistory) {
                    $cost = $this->pdo()->prepare('SELECT unit_hpp FROM marketplace_hpp_history WHERE store_id=? AND marketplace_sku=? AND effective_from<=? ORDER BY effective_from DESC,id DESC LIMIT 1');
                    $cost->execute([$storeId, $sku, $orderDate]);
                    $applyAmount = $cost->fetchColumn();
                    if ($applyAmount === false) {
                        continue;
                    }
                }
                $items = $this->pdo()->prepare('SELECT * FROM marketplace_order_items WHERE order_id=?');
                $items->execute([$order['id']]);
                $lines = $items->fetchAll(PDO::FETCH_ASSOC);
                $matched = false;
                $costDelta = 0.0;
                foreach ($lines as &$line) {
                    if ($line['sku'] === $sku) {
                        $matched = true;
                        $oldCost = (float) ($line['subtotal_cogs'] ?? 0);
                        $line['unit_cogs_hpp'] = (float) $applyAmount;
                        $line['subtotal_cogs'] = (float) $line['qty'] * (float) $applyAmount;
                        $costDelta += $line['subtotal_cogs'] - $oldCost;
                        $this->pdo()->prepare('UPDATE marketplace_order_items SET unit_cogs_hpp=?,subtotal_cogs=? WHERE id=? AND order_id=?')->execute([$applyAmount, $line['subtotal_cogs'], $line['id'], $order['id']]);
                    }
                } unset($line);
                $snapshot = json_decode((string) ($order['transaction_detail_json'] ?? ''), true);
                $snapshotMatched = false;
                if (is_array($snapshot) && isset($snapshot['items'])) {
                    $snapshotDelta = 0.0;
                    foreach ($snapshot['items'] as &$line) {
                        if (($line['sku'] ?? '') === $sku) {
                            $snapshotMatched = true;
                            $oldCost = (float) ($line['total_cogs'] ?? 0);
                            $line['unit_cogs'] = (float) $applyAmount;
                            $line['total_cogs'] = max(0, (float) $line['quantity'] - (float) ($line['returned_quantity'] ?? 0)) * (float) $applyAmount;
                            $snapshotDelta += $line['total_cogs'] - $oldCost;
                        }
                    } unset($line);
                }
                if (! $matched && ! $snapshotMatched) {
                    continue;
                }
                $total = (float) ($order['total_cogs_hpp'] ?? 0) + $costDelta;
                if ($snapshotMatched) {
                    $total = count(array_filter($snapshot['items'], static fn ($line) => ! isset($line['total_cogs']))) ? null : array_sum(array_column($snapshot['items'], 'total_cogs'));
                }
                if (is_array($snapshot) && $recalculateHistory) {
                    $snapshot['hpp_overrides'][$sku] = (float) $amount;
                } elseif (is_array($snapshot)) {
                    unset($snapshot['hpp_overrides'][$sku]);
                }
                if (is_array($snapshot)) {
                    $snapshot['total_cogs'] = $total;
                }
                $this->pdo()->prepare('UPDATE marketplace_orders SET total_cogs_hpp=?,transaction_detail_json=? WHERE id=? AND store_id=?')->execute([$total ?? 0, is_array($snapshot) ? json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null, $order['id'], $storeId]);
                $affected++;
            }
            if ($effectiveFrom <= date('Y-m-d')) {
                $this->pdo()->prepare('UPDATE marketplace_product_ads SET unit_cogs_hpp=? WHERE store_id=? AND sku=?')->execute([$amount, $storeId, $sku]);
            }
            $this->pdo()->prepare('INSERT INTO marketplace_hpp_changes(store_id,sku,old_hpp,new_hpp,changed_by,affected_orders) VALUES (?,?,?,?,?,?)')->execute([$storeId, $sku, $oldHpp === false ? null : $oldHpp, $amount, $actor, $affected]);
            if ($ownsTransaction) {
                $this->pdo()->commit();
            }

return $affected;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            } throw $error;
        }
    }

    private function pdo(): PDO
    {
        return $this->database->connection()->getPdo();
    }
}
