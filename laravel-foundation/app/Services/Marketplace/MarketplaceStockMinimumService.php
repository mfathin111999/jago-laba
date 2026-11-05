<?php

namespace App\Services\Marketplace;

use App\Auth\LegacyUser;
use DomainException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Sets the per-variant (and optional per-warehouse) minimum stock threshold.
 * Local write only: marketplace stock and HPP are never changed.
 */
final class MarketplaceStockMinimumService
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function setMinimum(LegacyUser $user, string $companyKey, string $storeId, string $productId, string $variantId, string $warehouseId, string $amount): void
    {
        if (! preg_match('/^\d{1,10}(?:\.\d{1,3})?$/', $amount)) {
            throw new InvalidArgumentException('Minimum stok harus nol atau angka positif, maksimal 3 desimal.');
        }

        $connection = $this->database->connection();
        $this->assertStoreInCompany($storeId, $companyKey);
        $variant = ['store_id' => $storeId, 'external_product_id' => $productId, 'external_variant_id' => $variantId, 'catalog_visible' => 1];
        if (! $connection->table('marketplace_catalog_variants')->where($variant)->exists()) {
            throw new DomainException('Varian aktif tidak ditemukan di toko ini.');
        }
        if ($warehouseId !== '' && ! $this->warehouseBelongsToVariant($storeId, $productId, $variantId, $warehouseId)) {
            throw new DomainException('Gudang tidak cocok dengan varian toko.');
        }

        $actor = $user->getLegacyUserId();
        $identity = ['store_id' => $storeId, 'product_id' => $productId, 'variant_id' => $variantId, 'warehouse_id' => $warehouseId];
        $connection->transaction(function () use ($connection, $variant, $identity, $companyKey, $amount, $actor): void {
            $connection->table('marketplace_catalog_variants')->where($variant)->lockForUpdate()->first();
            $existing = $connection->table('marketplace_stock_minimums')->where($identity)->lockForUpdate()->first(['minimum_qty']);
            $values = ['minimum_qty' => $amount, 'changed_by' => $actor, 'changed_at' => now()];

            if ($existing === null) {
                $connection->table('marketplace_stock_minimums')->insert($identity + $values);
            } else {
                $connection->table('marketplace_stock_minimums')->where($identity)->update($values);
            }

            $connection->table('marketplace_stock_minimum_audit')->insert([
                'company_key' => $companyKey, 'store_id' => $identity['store_id'], 'product_id' => $identity['product_id'],
                'variant_id' => $identity['variant_id'], 'warehouse_id' => $identity['warehouse_id'],
                'previous_qty' => $existing?->minimum_qty, 'new_qty' => $amount, 'actor' => $actor, 'created_at' => now(),
            ]);
        });
    }

    private function assertStoreInCompany(string $storeId, string $companyKey): void
    {
        $exists = $this->database->connection()->table('marketplace_stores')
            ->where('id', $storeId)->where('company_key', $companyKey)->whereNull('archived_at')->exists();
        if (! $exists) {
            throw new DomainException('Toko marketplace tidak ditemukan dalam perusahaan aktif.');
        }
    }

    private function warehouseBelongsToVariant(string $storeId, string $productId, string $variantId, string $warehouseId): bool
    {
        $payload = $this->database->connection()->table('marketplace_sync_records')
            ->where(['store_id' => $storeId, 'resource_type' => 'product', 'external_id' => $productId])->value('payload_json');
        $product = json_decode((string) $payload, true) ?: [];

        foreach ($product['skus'] ?? [] as $sku) {
            if ((string) ($sku['id'] ?? '') !== $variantId) {
                continue;
            }
            foreach ($sku['inventory'] ?? [] as $item) {
                if ((string) ($item['warehouse_id'] ?? '') === $warehouseId) {
                    return true;
                }
            }
        }

        return false;
    }
}
