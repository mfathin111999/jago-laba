<?php

namespace App\Services\Marketplace\Catalog;

use App\Services\Marketplace\MarketplaceScope;
use Exception;
use Illuminate\Database\DatabaseManager;
use PDO;

final class SkuHppUpdater
{
    public function __construct(private readonly DatabaseManager $database, private readonly MarketplaceScope $scope) {}

    public function update(string $storeId, string $sku, float $newHpp, ?string $variantId = null): bool
    {
        $this->scope->assertStoreAccess($storeId);
        $this->pdo()->beginTransaction();
        try {
            // HPP marketplace affects its order items only, not konveksi masters.

            // Simpan mapping SKU
            $stmtMap = $this->pdo()->prepare('INSERT INTO marketplace_sku_mappings 
                (store_id, marketplace_sku, marketplace_product_name, product_variant_id, created_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE product_variant_id = VALUES(product_variant_id)');
            $stmtMap->execute([$storeId, $sku, "SKU Map: $sku", $variantId]);

            // HPP baru berlaku mulai tanggal efektif. Snapshot transaksi lama
            // tidak ditimpa; backfill historis harus menjadi aksi terpisah.
            $effectiveFrom = date('Y-m-d');
            $changedBy = (string) ($this->scope->username() ?: 'system');
            $stmtHpp = $this->pdo()->prepare("INSERT INTO marketplace_hpp_history
                (store_id, marketplace_sku, product_variant_id, unit_hpp, effective_from, source, reason, changed_by)
                VALUES (?, ?, ?, ?, ?, 'MANUAL', 'Perubahan HPP marketplace', ?)
                ON DUPLICATE KEY UPDATE unit_hpp=VALUES(unit_hpp), product_variant_id=VALUES(product_variant_id), changed_by=VALUES(changed_by), created_at=NOW()");
            $stmtHpp->execute([$storeId, $sku, $variantId ?: null, $newHpp, $effectiveFrom, $changedBy]);

            // Isi hanya snapshot order yang sebelumnya belum memiliki HPP.
            $this->pdo()->prepare('UPDATE marketplace_order_items oi
                JOIN marketplace_orders o ON o.id = oi.order_id
                SET oi.unit_cogs_hpp = ?, oi.subtotal_cogs = oi.qty * ?
                WHERE o.store_id = ? AND oi.sku = ? AND (oi.unit_cogs_hpp IS NULL OR oi.unit_cogs_hpp = 0)')
                ->execute([$newHpp, $newHpp, $storeId, $sku]);

            $this->pdo()->commit();

            return true;
        } catch (Exception $e) {
            $this->pdo()->rollBack();
            throw $e;
        }
    }

    private function pdo(): PDO
    {
        return $this->database->connection()->getPdo();
    }
}
