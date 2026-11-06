<?php

namespace App\Services\Marketplace\Marketing;

use App\Services\Marketplace\Concerns\RunsSql;
use App\Services\Marketplace\MarketplaceScope;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

final class MarketingDashboard
{
    use RunsSql;

    public function __construct(private readonly DatabaseManager $database, private readonly MarketplaceScope $scope) {}

    public function report(string $storeId, ?string $filter = null, ?string $search = null, ?string $platform = null, string $adType = 'Semua', bool $includePpn = false, array $dates = []): array
    {
        $allowed = $this->scope->accessibleStoreIds();
        if ($storeId !== 'ALL' && $storeId !== 'Semua Toko' && $storeId !== '') {
            $this->scope->assertStoreAccess($storeId);
            $allowed = [$storeId];
        }
        [$in, $params] = $this->inClause($allowed);
        $where = $allowed ? "WHERE pa.store_id IN ($in)" : 'WHERE 1=0';
        foreach (['date_start' => ['>=', 'period_start'], 'date_end' => ['<=', 'period_end']] as $key => [$operator, $column]) {
            if (! empty($dates[$key])) {
                $date = (string) $dates[$key];
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (! $parsed || $parsed->format('Y-m-d') !== $date) {
                    throw new InvalidArgumentException('Tanggal laporan iklan tidak valid.');
                }
                $where .= " AND pa.$column $operator ?";
                $params[] = $date;
            }
        }
        if (! empty($dates['date_start']) && ! empty($dates['date_end']) && $dates['date_start'] > $dates['date_end']) {
            throw new InvalidArgumentException('Rentang tanggal iklan tidak valid.');
        }

        if ($storeId !== 'ALL' && $storeId !== 'Semua Toko' && ! empty($storeId)) {
            $where .= ' AND pa.store_id = ?';
            $params[] = $storeId;
        }

        if ($platform && $platform !== 'Semua Platform') {
            $where .= ' AND pa.platform = ?';
            $params[] = $platform;
        }

        if ($adType && $adType !== 'Semua' && $adType !== 'Semua Tipe') {
            $where .= ' AND pa.ad_type = ?';
            $params[] = $adType;
        }

        if ($search) {
            $where .= ' AND (pa.product_name LIKE ? OR pa.sku LIKE ? OR pa.marketplace_item_id LIKE ? OR pa.campaign_name LIKE ?)';
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $sql = "SELECT pa.*, 
                    pa.unit_cogs_hpp as resolved_unit_hpp
                FROM marketplace_product_ads pa
                $where
                ORDER BY pa.ad_spend DESC";

        $rows = $this->rows($sql, $params);

        $totalAdSpend = 0;
        $totalRevenue = 0;
        $totalOrders = 0;
        $totalQty = 0;
        $totalAffiliateFee = 0;
        $totalClicks = 0;
        $totalImpressions = 0;
        $totalConversions = 0;
        $processedProducts = [];
        $bocorCount = 0;

        $ppnFactor = $includePpn ? 1.11 : 1.0;

        foreach ($rows as $r) {
            // Kalkulasi PPN 11% jika toggle aktif
            $baseSpend = (float) $r['ad_spend'];
            $adSpend = round($baseSpend * $ppnFactor, 2);
            $ppnAmount = $includePpn ? round($baseSpend * 0.11, 2) : 0.0;

            $revenue = (float) $r['gross_revenue'];
            $orders = (int) $r['orders_count'];
            $clicks = (int) ($r['clicks'] ?? 0);
            $impressions = (int) ($r['impressions'] ?? 0);
            $conversions = (int) ($r['conversions'] ?? 0);
            $qty = (int) $r['qty_sold'];
            $unitHpp = (float) $r['resolved_unit_hpp'];
            $totalHpp = $unitHpp * $qty;
            $platformFee = (float) $r['platform_fee'];
            $affiliateFee = (float) $r['affiliate_fee'];

            // ROAS Calculations
            $grossRoas = $adSpend > 0 ? round($revenue / $adSpend, 2) : 0.0;
            $netRoas = $adSpend > 0 ? round(($revenue - $platformFee - $affiliateFee) / $adSpend, 2) : 0.0;
            $actualRoas = $adSpend > 0 ? round(($revenue - $platformFee - $affiliateFee - $totalHpp) / $adSpend, 2) : 0.0;

            // Break Even ROAS (Min ROAS)
            $profitMarginBeforeAd = $revenue - $platformFee - $affiliateFee - $totalHpp;
            $minRoas = $profitMarginBeforeAd > 0 ? round($revenue / $profitMarginBeforeAd, 2) : 99.0;
            $isAman = $actualRoas >= 1.0 && $grossRoas >= $minRoas && $profitMarginBeforeAd > 0;

            // Deteksi Otomatis Produk Bocor (Early Warning Alert)
            $isBocor = (int) $r['is_bocor'] === 1;
            $bocorReason = $r['bocor_reason'];
            if (! $isBocor) {
                if ($adSpend >= 100000 && $orders === 0) {
                    $isBocor = true;
                    $bocorReason = 'Biaya Iklan Rp '.number_format($adSpend, 0, ',', '.').' tanpa hasil pesanan dalam 24 jam! Segera jeda iklan.';
                } elseif ($profitMarginBeforeAd > 0 && $grossRoas < $minRoas && $adSpend > 150000) {
                    $isBocor = true;
                    $bocorReason = "Actual ROAS ({$grossRoas}x) di bawah Min ROAS ({$minRoas}x). Margin produk boncos!";
                }
            }
            if ($isBocor) {
                $bocorCount++;
            }

            // Margin & Profit
            $costPerOrder = $orders > 0 ? round($adSpend / $orders) : 0;
            $marginLabaPct = $revenue > 0 ? round((($revenue - $totalHpp) / $revenue) * 100, 1) : 0.0;
            $profitRiil = $revenue - $platformFee - $affiliateFee - $totalHpp;
            $profitRiilPct = $revenue > 0 ? round(($profitRiil / $revenue) * 100, 1) : 0.0;
            $platformFeePct = $revenue > 0 ? round(($platformFee / $revenue) * 100, 1) : 0.0;

            // Net Profit Akhir (setelah potong iklan & affiliate)
            $netProfit = $profitRiil - $adSpend;
            $netProfitPct = $revenue > 0 ? round(($netProfit / $revenue) * 100, 1) : 0.0;

            // Filter Pills
            if ($filter === 'roas_rendah' && $isAman) {
                continue;
            }
            if ($filter === 'tanpa_hpp' && $unitHpp > 0) {
                continue;
            }
            if ($filter === 'rugi' && $netProfit >= 0) {
                continue;
            }
            if ($filter === 'bocor' && ! $isBocor) {
                continue;
            }

            $totalAdSpend += $adSpend;
            $totalRevenue += $revenue;
            $totalOrders += $orders;
            $totalQty += $qty;
            $totalAffiliateFee += $affiliateFee;
            $totalClicks += $clicks;
            $totalImpressions += $impressions;
            $totalConversions += $conversions;

            $processedProducts[] = [
                'id' => $r['id'],
                'marketplace_item_id' => $r['marketplace_item_id'],
                'sku' => $r['sku'],
                'product_name' => $r['product_name'],
                'campaign_name' => $r['campaign_name'] ?? null,
                'ad_type' => $r['ad_type'] ?? 'Produk',
                'image_url' => $r['image_url'] ?: '',
                'store_id' => $r['store_id'],
                'period_start' => $r['period_start'],
                'period_end' => $r['period_end'],
                'last_synced_at' => $r['last_synced_at'],
                'platform' => $r['platform'],
                'ad_spend' => $adSpend,
                'base_spend' => $baseSpend,
                'ppn_amount' => $ppnAmount,
                'gross_revenue' => $revenue,
                'gross_roas' => $grossRoas,
                'net_roas' => $netRoas,
                'actual_roas' => $actualRoas,
                'min_roas' => $minRoas,
                'is_aman' => $isAman,
                'is_bocor' => $isBocor,
                'bocor_reason' => $bocorReason,
                'orders_count' => $orders,
                'qty_sold' => $qty,
                'cost_per_order' => $costPerOrder,
                'unit_cogs_hpp' => $unitHpp,
                'total_cogs_hpp' => $totalHpp,
                'margin_laba_pct' => $marginLabaPct,
                'profit_riil' => $profitRiil,
                'profit_riil_pct' => $profitRiilPct,
                'platform_fee' => $platformFee,
                'platform_fee_pct' => $platformFeePct,
                'affiliate_fee' => $affiliateFee,
                'net_profit' => $netProfit,
                'net_profit_pct' => $netProfitPct,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'conversions' => $conversions,
                'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0,
                'cpc' => $clicks > 0 ? round($adSpend / $clicks) : 0.0,
            ];
        }

        // KPI berasal dari baris laporan yang sama dengan tabel, tanpa angka referensi.
        $kpiSpend = $totalAdSpend;
        $kpiRevenue = $totalRevenue;
        $kpiRoas = $totalAdSpend > 0 ? round($totalRevenue / $totalAdSpend, 2) : 0.0;
        $kpiCostPerOrder = $totalOrders > 0 ? round($totalAdSpend / $totalOrders) : 0.0;
        $kpiOrders = $totalOrders;

        return [
            'kpi' => [
                'biaya_iklan' => $kpiSpend,
                'pendapatan' => $kpiRevenue,
                'roas' => $kpiRoas,
                'cost_per_order' => $kpiCostPerOrder,
                'total_orders' => $kpiOrders,
                'total_affiliate_fee' => $totalAffiliateFee,
                'include_ppn' => $includePpn,
                'bocor_count' => $bocorCount,
                'total_clicks' => $totalClicks,
                'total_impressions' => $totalImpressions,
                'total_conversions' => $totalConversions,
                'ctr' => $totalImpressions > 0 ? round(($totalClicks / $totalImpressions) * 100, 2) : 0.0,
                'cpc' => $totalClicks > 0 ? round($totalAdSpend / $totalClicks) : 0.0,
                'cpm' => $totalImpressions > 0 ? round(($totalAdSpend / $totalImpressions) * 1000) : 0.0,
                'conversion_rate' => $totalClicks > 0 ? round(($totalOrders / $totalClicks) * 100, 2) : 0.0,
                'campaign_count' => count(array_filter(array_unique(array_column($processedProducts, 'campaign_name')))) ?: count($processedProducts),
            ],
            'products' => $processedProducts,
            'total_count' => count($processedProducts),
        ];
    }

    private function inClause(array $ids): array
    {
        return [implode(',', array_fill(0, count($ids), '?')), array_values($ids)];
    }
}
