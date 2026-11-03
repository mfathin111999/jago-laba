<?php

namespace App\Pilots\Marketplace;

use App\Audit\AuditLogger;
use App\Auth\LegacyUser;
use DomainException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

final class MarketplaceSettingsService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditLogger $audit,
    ) {}

    public function simulatePrice(array $p): array
    {
        $cogsHpp = (float) ($p['cogs_hpp'] ?? 0);
        $targetMarginPct = (float) ($p['target_net_margin_pct'] ?? 25.0);
        $adminFeePct = (float) ($p['admin_fee_pct'] ?? 11.0);
        $freeShippingPct = (float) ($p['free_shipping_xtra_pct'] ?? 4.0);
        $paymentFeePct = (float) ($p['payment_fee_pct'] ?? 1.0);
        $adBudgetPct = (float) ($p['ad_budget_pct'] ?? 10.0);
        $productName = trim((string) ($p['product_name'] ?? 'Simulasi Produk'));
        $sku = trim((string) ($p['sku'] ?? ('SIM-'.time())));

        if ($cogsHpp <= 0) {
            throw new InvalidArgumentException('HPP Pokok Produk harus lebih besar dari 0.');
        }

        $totalPlatformFeePct = $adminFeePct + $freeShippingPct + $paymentFeePct;
        $totalDeductionPct = $totalPlatformFeePct + $adBudgetPct;

        $denominatorNormal = 1 - (($totalDeductionPct + $targetMarginPct) / 100);
        if ($denominatorNormal <= 0.1) {
            $denominatorNormal = 0.1;
        }
        $normalPrice = round(($cogsHpp / $denominatorNormal) / 100) * 100;

        $flashMarginPct = 5.0;
        $denominatorFlash = 1 - (($totalPlatformFeePct + $flashMarginPct) / 100);
        $flashSalePrice = round(($cogsHpp / max(0.1, $denominatorFlash)) / 100) * 100;

        $revenueAfterPlatformFee = $normalPrice * (1 - ($totalPlatformFeePct / 100));
        $grossProfitBeforeAds = $revenueAfterPlatformFee - $cogsHpp;
        $breakEvenRoas = $grossProfitBeforeAds > 0 ? round($normalPrice / $grossProfitBeforeAds, 2) : 99.0;
        $netProfitPerPcs = round($normalPrice * ($targetMarginPct / 100));

        if (! empty($p['save_simulation'])) {
            $connection = $this->database->connection();
            if (\Illuminate\Support\Facades\Schema::hasTable('marketplace_price_simulations')) {
                $connection->table('marketplace_price_simulations')->insert([
                    'product_name' => $productName,
                    'sku' => $sku,
                    'cogs_hpp' => $cogsHpp,
                    'target_net_margin_pct' => $targetMarginPct,
                    'admin_fee_pct' => $adminFeePct,
                    'free_shipping_xtra_pct' => $freeShippingPct,
                    'payment_fee_pct' => $paymentFeePct,
                    'ad_budget_pct' => $adBudgetPct,
                    'simulated_normal_price' => $normalPrice,
                    'simulated_flash_sale_price' => $flashSalePrice,
                    'break_even_roas' => $breakEvenRoas,
                    'net_profit_per_pcs' => $netProfitPerPcs,
                    'created_at' => now(),
                ]);
            }
        }

        return [
            'product_name' => $productName,
            'sku' => $sku,
            'cogs_hpp' => $cogsHpp,
            'target_net_margin_pct' => $targetMarginPct,
            'admin_fee_pct' => $adminFeePct,
            'free_shipping_xtra_pct' => $freeShippingPct,
            'payment_fee_pct' => $paymentFeePct,
            'ad_budget_pct' => $adBudgetPct,
            'total_platform_fee_pct' => $totalPlatformFeePct,
            'simulated_normal_price' => $normalPrice,
            'simulated_flash_sale_price' => $flashSalePrice,
            'rec_normal_price' => $normalPrice,
            'rec_flash_sale_price' => $flashSalePrice,
            'break_even_roas' => $breakEvenRoas,
            'min_break_even_roas' => $breakEvenRoas,
            'net_profit_per_pcs' => $netProfitPerPcs,
            'recommendation' => "Iklan aman jika ROAS harian >= {$breakEvenRoas}x. Jika ROAS < {$breakEvenRoas}x, segera evaluasi kata kunci atau naikkan harga jual.",
        ];
    }

    public function getSavedPriceSimulations(): array
    {
        $connection = $this->database->connection();
        if (! \Illuminate\Support\Facades\Schema::hasTable('marketplace_price_simulations')) {
            return [];
        }

        return $connection->table('marketplace_price_simulations')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    public function getStoreDetail(string $companyKey, string $storeId): ?array
    {
        $connection = $this->database->connection();
        $query = $connection->table('marketplace_stores as s')
            ->where('s.id', $storeId)
            ->where('s.company_key', $companyKey);

        if (\Illuminate\Support\Facades\Schema::hasTable('marketplace_api_credentials')) {
            $query->leftJoin('marketplace_api_credentials as c', 'c.store_id', '=', 's.id')
                ->select([
                    's.*',
                    'c.partner_id',
                    'c.shop_id',
                    'c.app_key',
                    'c.webhook_url',
                    'c.connection_status',
                ]);
        } else {
            $query->select('s.*');
        }

        $row = $query->first();
        if ($row) {
            $data = (array) $row;
            unset($data['api_access_token'], $data['api_app_key']);
            $data['connection_status'] = 'Perlu Otorisasi';

            return $data;
        }

        return null;
    }

    public function saveAppSettings(LegacyUser $user, array $settings): array
    {
        $connection = $this->database->connection();
        if (! \Illuminate\Support\Facades\Schema::hasTable('marketplace_app_settings')) {
            return ['success' => true, 'updated_count' => 0, 'message' => 'Tabel pengaturan belum ada.'];
        }

        $updatedCount = 0;
        foreach ($settings as $key => $val) {
            if ($key === 'action') {
                continue;
            }
            $affected = $connection->table('marketplace_app_settings')
                ->where('setting_key', $key)
                ->update([
                    'setting_value' => (string) $val,
                    'updated_at' => now(),
                ]);
            if ($affected > 0) {
                $updatedCount++;
            }
        }

        $this->audit->record(
            $user,
            'Marketplace',
            'SAVE_APP_SETTINGS',
            'SETTINGS',
            'GLOBAL',
            null,
            $settings,
            "Simpan pengaturan aplikasi ({$updatedCount} diperbarui)",
        );

        return [
            'success' => true,
            'updated_count' => $updatedCount,
            'message' => 'Pengaturan aplikasi marketplace berhasil disimpan dan disinkronkan.',
        ];
    }
}
