<?php

namespace App\Services\Marketplace;

use App\Audit\AuditLogger;
use App\Auth\AuthenticatedUser;
use App\Services\Marketplace\Catalog\CatalogService;
use App\Services\Marketplace\Catalog\SkuHppUpdater;
use InvalidArgumentException;

final class CatalogHppWriteService
{
    public function __construct(
        private readonly MarketplaceScope $scope,
        private readonly CatalogService $catalog,
        private readonly SkuHppUpdater $skuHpp,
        private readonly AuditLogger $audit,
    ) {}

    public function updateSkuHpp(AuthenticatedUser $user, string $companyKey, string $storeId, string $sku, float $newHpp, ?string $variantId = null): array
    {
        $sku = trim($sku);
        if ($sku === '' || $newHpp <= 0) {
            throw new InvalidArgumentException('SKU dan HPP harus valid.');
        }

        $this->scope->run($user, $companyKey, fn (): bool => $this->skuHpp->update($storeId, $sku, $newHpp, $variantId));

        $this->audit->record(
            $user,
            'Marketplace',
            'UPDATE_SKU_HPP',
            $sku,
            $storeId,
            null,
            [
                'store_id' => $storeId,
                'sku' => $sku,
                'hpp' => $newHpp,
                'variant_id' => $variantId,
            ],
            "Update HPP untuk SKU {$sku} ke {$newHpp}",
        );

        return [
            'success' => true,
            'store_id' => $storeId,
            'sku' => $sku,
            'hpp' => $newHpp,
            'message' => "HPP untuk SKU {$sku} berhasil diperbarui ke Rp ".number_format($newHpp, 0, ',', '.').' dan disinkronkan ke master stok.',
        ];
    }

    public function updateGlobalProductHpp(
        AuthenticatedUser $user,
        string $companyKey,
        string $storeId,
        string $amount,
        bool $applyAllVariants,
        ?string $productId,
        ?string $sku,
        bool $recalculateHistory = false,
        ?string $effectiveFrom = null,
    ): array {
        $affected = $this->scope->run($user, $companyKey, function () use (
            $storeId, $amount, $applyAllVariants, $productId, $sku, $recalculateHistory, $effectiveFrom
        ): int {
            if ($applyAllVariants) {
                if (empty($productId)) {
                    throw new InvalidArgumentException('Product ID wajib diisi jika menerapkan ke semua varian.');
                }

                return $this->catalog->updateProductHpp($storeId, $productId, $amount, $recalculateHistory, $effectiveFrom);
            }

            if (empty($sku)) {
                throw new InvalidArgumentException('SKU wajib diisi.');
            }

            return $this->catalog->updateHpp($storeId, $sku, $amount, $recalculateHistory, $effectiveFrom);
        });

        $this->audit->record(
            $user,
            'Marketplace',
            'UPDATE_GLOBAL_PRODUCT_HPP',
            $applyAllVariants ? (string) $productId : (string) $sku,
            $storeId,
            null,
            [
                'store_id' => $storeId,
                'amount' => $amount,
                'apply_all_variants' => $applyAllVariants,
                'product_id' => $productId,
                'sku' => $sku,
                'recalculate_history' => $recalculateHistory,
                'effective_from' => $effectiveFrom,
                'affected_orders' => $affected,
            ],
            "HPP tersimpan. {$affected} transaksi diperbarui.",
        );

        return [
            'success' => true,
            'affected_orders' => $affected,
            'message' => "HPP tersimpan. {$affected} transaksi diperbarui. ".($recalculateHistory ? 'Histori dihitung ulang sesuai pilihan Anda.' : 'Transaksi sebelum tanggal berlaku dipertahankan.'),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $changes
     * @return array<string, mixed>
     */
    public function saveHppBatch(AuthenticatedUser $user, string $companyKey, array $changes): array
    {
        $result = $this->scope->run($user, $companyKey, fn (): array => $this->catalog->updateHppBatch($changes));

        $skuCount = (int) ($result['sku_count'] ?? count($changes));
        $this->audit->record(
            $user,
            'Marketplace',
            'SAVE_HPP_BATCH',
            'BATCH-'.count($changes),
            $companyKey,
            null,
            [
                'sku_count' => $skuCount,
                'affected_order_updates' => $result['affected_order_updates'] ?? 0,
            ],
            "{$skuCount} SKU tersimpan secara batch.",
        );

        return [
            'success' => true,
            'data' => $result,
            'message' => "{$skuCount} SKU tersimpan. HPP dan laba transaksi terkait sudah diperbarui.",
        ];
    }
}
