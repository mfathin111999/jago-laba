<?php

namespace App\Pilots\Marketplace;

use App\Audit\AuditLogger;
use App\Auth\LegacyUser;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class MarketplaceHppService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditLogger $audit,
    ) {}

    public function updateSkuHpp(LegacyUser $user, string $companyKey, string $storeId, string $sku, float $newHpp, ?string $variantId = null): array
    {
        $companyKey = trim($companyKey);
        $storeId = trim($storeId);
        $sku = trim($sku);
        if ($sku === '' || $newHpp <= 0) {
            throw new InvalidArgumentException('SKU dan HPP harus valid.');
        }

        $connection = $this->database->connection();
        $store = $connection->table('marketplace_stores')
            ->where('id', $storeId)
            ->where('company_key', $companyKey)
            ->whereNull('archived_at')
            ->first();

        if ($store === null) {
            throw new DomainException('Toko marketplace tidak ditemukan dalam perusahaan aktif.', 403);
        }

        $connection->transaction(function () use ($connection, $storeId, $sku, $newHpp, $variantId, $user): void {
            // Upsert SKU mapping
            $mapping = $connection->table('marketplace_sku_mappings')
                ->where('store_id', $storeId)
                ->where('marketplace_sku', $sku)
                ->first();

            if ($mapping) {
                $connection->table('marketplace_sku_mappings')
                    ->where('store_id', $storeId)
                    ->where('marketplace_sku', $sku)
                    ->update([
                        'product_variant_id' => $variantId,
                    ]);
            } else {
                $connection->table('marketplace_sku_mappings')->insert([
                    'store_id' => $storeId,
                    'marketplace_sku' => $sku,
                    'marketplace_product_name' => "SKU Map: {$sku}",
                    'product_variant_id' => $variantId,
                    'created_at' => now(),
                ]);
            }

            // Record HPP history
            $effectiveFrom = now('Asia/Jakarta')->format('Y-m-d');
            $changedBy = (string) ($user->getUsername() ?: $user->getAuthIdentifier() ?: 'system');

            $existingHistory = $connection->table('marketplace_hpp_history')
                ->where('store_id', $storeId)
                ->where('marketplace_sku', $sku)
                ->where('effective_from', $effectiveFrom)
                ->first();

            if ($existingHistory) {
                $connection->table('marketplace_hpp_history')
                    ->where('id', $existingHistory->id)
                    ->update([
                        'unit_hpp' => $newHpp,
                        'product_variant_id' => $variantId,
                        'changed_by' => $changedBy,
                        'created_at' => now(),
                    ]);
            } else {
                $connection->table('marketplace_hpp_history')->insert([
                    'store_id' => $storeId,
                    'marketplace_sku' => $sku,
                    'product_variant_id' => $variantId,
                    'unit_hpp' => $newHpp,
                    'effective_from' => $effectiveFrom,
                    'source' => 'MANUAL',
                    'reason' => 'Perubahan HPP marketplace',
                    'changed_by' => $changedBy,
                    'created_at' => now(),
                ]);
            }

            // Update order items without HPP
            $orderIds = $connection->table('marketplace_orders')
                ->where('store_id', $storeId)
                ->pluck('id');

            if ($orderIds->isNotEmpty()) {
                $connection->table('marketplace_order_items')
                    ->whereIn('order_id', $orderIds)
                    ->where('sku', $sku)
                    ->where(function ($query): void {
                        $query->whereNull('unit_cogs_hpp')->orWhere('unit_cogs_hpp', 0);
                    })
                    ->update([
                        'unit_cogs_hpp' => $newHpp,
                    ]);
            }
        });

        $this->audit->record(
            $user,
            'Marketplace',
            'UPDATE_SKU_HPP',
            $sku,
            $storeId,
            null,
            ['store_id' => $storeId, 'sku' => $sku, 'hpp' => $newHpp, 'variant_id' => $variantId],
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

    public function updateHpp(
        LegacyUser $user,
        string $companyKey,
        string $storeId,
        string $sku,
        string $amount,
        bool $recalculateHistory = false,
        ?string $effectiveFrom = null,
    ): int {
        $storeId = trim($storeId);
        $sku = trim($sku);
        if ($sku === '' || ! preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $amount)) {
            throw new InvalidArgumentException('SKU atau HPP tidak valid.');
        }

        $effectiveFrom = $effectiveFrom ?: now('Asia/Jakarta')->format('Y-m-d');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveFrom);
        if (! $date || $date->format('Y-m-d') !== $effectiveFrom) {
            throw new InvalidArgumentException('Tanggal mulai HPP tidak valid.');
        }

        $connection = $this->database->connection();
        $store = $connection->table('marketplace_stores')
            ->where('id', $storeId)
            ->where('company_key', $companyKey)
            ->whereNull('archived_at')
            ->first();

        if ($store === null) {
            throw new DomainException('Toko marketplace tidak ditemukan dalam perusahaan aktif.', 403);
        }

        // Check SKU exists in store catalog, order items, or sku mappings
        $inCatalog = $connection->table('marketplace_catalog_variants')
            ->where('store_id', $storeId)
            ->where('sku', $sku)
            ->exists();
        $inOrders = $connection->table('marketplace_order_items as oi')
            ->join('marketplace_orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.store_id', $storeId)
            ->where('oi.sku', $sku)
            ->exists();
        $inMappings = $connection->table('marketplace_sku_mappings')
            ->where('store_id', $storeId)
            ->where('marketplace_sku', $sku)
            ->exists();

        if (! $inCatalog && ! $inOrders && ! $inMappings) {
            throw new DomainException('SKU tidak ditemukan di toko aktif.');
        }

        $today = now('Asia/Jakarta')->format('Y-m-d');
        $oldHpp = $connection->table('marketplace_hpp_history')
            ->where('store_id', $storeId)
            ->where('marketplace_sku', $sku)
            ->where('effective_from', '<=', $today)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->value('unit_hpp');

        $actor = (string) ($user->getUsername() ?: $user->getAuthIdentifier() ?: 'system');

        return $connection->transaction(function () use (
            $connection, $storeId, $sku, $amount, $effectiveFrom, $recalculateHistory,
            $actor, $oldHpp, $today
        ): int {
            $existing = $connection->table('marketplace_hpp_history')
                ->where('store_id', $storeId)
                ->where('marketplace_sku', $sku)
                ->where('effective_from', $effectiveFrom)
                ->first();

            if ($existing) {
                $connection->table('marketplace_hpp_history')
                    ->where('id', $existing->id)
                    ->update([
                        'unit_hpp' => $amount,
                        'reason' => $recalculateHistory ? 'Hitung ulang histori secara eksplisit' : 'Edit HPP berlaku mulai tanggal dipilih',
                        'changed_by' => $actor,
                    ]);
            } else {
                $connection->table('marketplace_hpp_history')->insert([
                    'store_id' => $storeId,
                    'marketplace_sku' => $sku,
                    'unit_hpp' => $amount,
                    'effective_from' => $effectiveFrom,
                    'source' => 'MANUAL',
                    'reason' => $recalculateHistory ? 'Hitung ulang histori secara eksplisit' : 'Edit HPP berlaku mulai tanggal dipilih',
                    'changed_by' => $actor,
                ]);
            }

            // Serialise concurrent HPP edits of the same store while its orders are recalculated.
            $connection->table('marketplace_stores')->where('id', $storeId)->lockForUpdate()->first();
            $orders = $connection->table('marketplace_orders')
                ->where('store_id', $storeId)
                ->lockForUpdate()
                ->get();

            $affected = 0;
            foreach ($orders as $order) {
                $orderDate = substr((string) $order->order_date, 0, 10);
                if (! $recalculateHistory && $orderDate < $effectiveFrom) {
                    continue;
                }

                $applyAmount = $amount;
                if (! $recalculateHistory) {
                    $cost = $connection->table('marketplace_hpp_history')
                        ->where('store_id', $storeId)
                        ->where('marketplace_sku', $sku)
                        ->where('effective_from', '<=', $orderDate)
                        ->orderByDesc('effective_from')
                        ->orderByDesc('id')
                        ->value('unit_hpp');
                    if ($cost === null) {
                        continue;
                    }
                    $applyAmount = (string) $cost;
                }

                $lines = $connection->table('marketplace_order_items')
                    ->where('order_id', $order->id)
                    ->get();

                $matched = false;
                $costDelta = 0.0;
                foreach ($lines as $line) {
                    if ($line->sku === $sku) {
                        $matched = true;
                        $oldCost = (float) ($line->subtotal_cogs ?? 0);
                        $newSubtotal = (float) $line->qty * (float) $applyAmount;
                        $costDelta += $newSubtotal - $oldCost;
                        $connection->table('marketplace_order_items')
                            ->where('id', $line->id)
                            ->where('order_id', $order->id)
                            ->update([
                                'unit_cogs_hpp' => (float) $applyAmount,
                                'subtotal_cogs' => $newSubtotal,
                            ]);
                    }
                }

                $snapshot = json_decode((string) ($order->transaction_detail_json ?? ''), true);
                $snapshotMatched = false;
                if (is_array($snapshot) && isset($snapshot['items']) && is_array($snapshot['items'])) {
                    $snapshotDelta = 0.0;
                    foreach ($snapshot['items'] as &$line) {
                        if (($line['sku'] ?? '') === $sku) {
                            $snapshotMatched = true;
                            $oldCost = (float) ($line['total_cogs'] ?? 0);
                            $line['unit_cogs'] = (float) $applyAmount;
                            $line['total_cogs'] = max(0, (float) ($line['quantity'] ?? 0) - (float) ($line['returned_quantity'] ?? 0)) * (float) $applyAmount;
                            $snapshotDelta += $line['total_cogs'] - $oldCost;
                        }
                    }
                    unset($line);
                }

                if (! $matched && ! $snapshotMatched) {
                    continue;
                }

                $total = (float) ($order->total_cogs_hpp ?? 0) + $costDelta;
                if ($snapshotMatched) {
                    $hasMissing = count(array_filter($snapshot['items'], static fn ($line): bool => ! isset($line['total_cogs']))) > 0;
                    $total = $hasMissing ? null : array_sum(array_column($snapshot['items'], 'total_cogs'));
                }

                if (is_array($snapshot) && $recalculateHistory) {
                    $snapshot['hpp_overrides'][$sku] = (float) $amount;
                } elseif (is_array($snapshot)) {
                    unset($snapshot['hpp_overrides'][$sku]);
                }

                if (is_array($snapshot)) {
                    $snapshot['total_cogs'] = $total;
                }

                $net = is_array($snapshot) && array_key_exists('net_income', $snapshot) ? $snapshot['net_income'] : $order->net_settlement_amount;
                $profit = $net === null || $total === null ? null : (float) $net - (float) $total;

                $updateData = [
                    'total_cogs_hpp' => $total ?? 0,
                    'transaction_detail_json' => is_array($snapshot) ? json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null,
                ];
                if (property_exists($order, 'net_margin_profit')) {
                    $updateData['net_margin_profit'] = $profit;
                }

                $connection->table('marketplace_orders')
                    ->where('id', $order->id)
                    ->where('store_id', $storeId)
                    ->update($updateData);

                $affected++;
            }

            if ($effectiveFrom <= $today && Schema::hasTable('marketplace_product_ads')) {
                $connection->table('marketplace_product_ads')
                    ->where('store_id', $storeId)
                    ->where('sku', $sku)
                    ->update(['unit_cogs_hpp' => (float) $amount]);
            }

            if (Schema::hasTable('marketplace_hpp_changes')) {
                $connection->table('marketplace_hpp_changes')->insert([
                    'store_id' => $storeId,
                    'sku' => $sku,
                    'old_hpp' => $oldHpp !== null ? (float) $oldHpp : null,
                    'new_hpp' => (float) $amount,
                    'changed_by' => $actor,
                    'affected_orders' => $affected,
                    'created_at' => now(),
                ]);
            }

            return $affected;
        });
    }

    public function updateProductHpp(
        LegacyUser $user,
        string $companyKey,
        string $storeId,
        string $productId,
        string $amount,
        bool $recalculateHistory = false,
        ?string $effectiveFrom = null,
    ): int {
        $connection = $this->database->connection();
        $skus = $connection->table('marketplace_catalog_variants')
            ->where('store_id', $storeId)
            ->where('external_product_id', $productId)
            ->where('catalog_visible', 1)
            ->whereIn($connection->raw('UPPER(source_status)'), ['ACTIVATE', 'NORMAL', 'AKTIF', 'ACTIVE'])
            ->distinct()
            ->pluck('sku')
            ->all();

        if ($skus === [] || in_array('', $skus, true)) {
            throw new DomainException('SKU produk belum lengkap. Atur HPP per varian yang memiliki SKU.');
        }

        $affected = 0;
        foreach ($skus as $sku) {
            $affected += $this->updateHpp($user, $companyKey, $storeId, (string) $sku, $amount, $recalculateHistory, $effectiveFrom);
        }

        return $affected;
    }

    /**
     * @param  array<int, array<string, mixed>>  $changes
     * @return array<string, int>
     */
    public function updateHppBatch(LegacyUser $user, string $companyKey, array $changes): array
    {
        if ($changes === [] || count($changes) > 500) {
            throw new InvalidArgumentException('Pilih 1–500 SKU untuk disimpan.');
        }

        $connection = $this->database->connection();
        $validated = [];

        foreach ($changes as $change) {
            if (! is_array($change)) {
                throw new InvalidArgumentException('Format perubahan tidak valid.');
            }
            $store = (string) ($change['store_id'] ?? '');
            $sku = (string) ($change['sku'] ?? '');
            $amount = (string) ($change['hpp'] ?? '');

            // Verify store access
            $storeRow = $connection->table('marketplace_stores')
                ->where('id', $store)
                ->where('company_key', $companyKey)
                ->whereNull('archived_at')
                ->first();
            if ($storeRow === null) {
                throw new DomainException("Toko {$store} tidak ditemukan dalam perusahaan aktif.", 403);
            }

            if ($sku === '' || ! preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $amount)) {
                throw new InvalidArgumentException('SKU atau HPP tidak valid.');
            }

            $key = $store."\0".$sku;
            if (isset($validated[$key])) {
                throw new InvalidArgumentException('SKU yang sama dikirim lebih dari sekali.');
            }

            $q = $connection->table('marketplace_catalog_variants')
                ->where('store_id', $store)
                ->where('sku', $sku)
                ->where('catalog_visible', 1)
                ->whereIn($connection->raw('UPPER(source_status)'), ['ACTIVATE', 'NORMAL', 'AKTIF', 'ACTIVE'])
                ->count();
            if ($q === 0) {
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
        $affected = 0;
        foreach ($validated as $args) {
            $affected += $this->updateHpp(
                $user,
                $companyKey,
                (string) $args[0],
                (string) $args[1],
                (string) $args[2],
                (bool) $args[3],
                $args[4] ? (string) $args[4] : null,
            );
        }

        return ['sku_count' => count($validated), 'affected_order_updates' => $affected];
    }
}
