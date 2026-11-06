<?php

namespace App\Services\Marketplace\Sync;

final class FinanceNormalizer
{
    private const MAP = [
        'platform_commission_amount' => ['platform_commission', 'Komisi Platform'],
        'transaction_fee_amount' => ['payment_fee', 'Biaya Transaksi'],
        'affiliate_commission_amount' => ['affiliate_commission', 'Komisi Affiliate'],
        'affiliate_ads_commission_amount' => ['ads_affiliate_commission', 'Komisi Ads Affiliate'],
        'dynamic_commission_amount' => ['dynamic_commission', 'Komisi Dinamis'],
        'sfp_service_fee_amount' => ['service_fee', 'Biaya Layanan Gratis Ongkir'],
        'live_specials_fee_amount' => ['live_commission', 'Biaya Layanan Live'],
        'bonus_cashback_service_fee_amount' => ['cashback_fee', 'Biaya Cashback'],
        'mall_service_fee_amount' => ['mall_fee', 'Biaya Mall'],
        'seller_paylater_handling_fee_amount' => ['paylater_fee', 'Biaya PayLater'],
        'article_22_income_tax_withheld' => ['withholding_tax', 'Pajak Pemotongan PPh 22'],
        'actual_shipping_fee_amount' => ['actual_shipping', 'Ongkir Aktual'],
        'shipping_fee_discount_amount' => ['shipping_discount', 'Subsidi/Diskon Ongkir'],
        'customer_paid_shipping_fee_amount' => ['buyer_shipping', 'Ongkir Dibayar Pembeli'],
        'seller_discount_amount' => ['seller_voucher', 'Diskon/Voucher Penjual'],
        'refund_subtotal_before_discount_amount' => ['buyer_refund', 'Pengembalian Dana Produk'],
        'seller_discount_refund_amount' => ['seller_discount_refund', 'Pengembalian Diskon Penjual'],
        'vn_fix_infrastructure_fee' => ['processing_fee', 'Biaya Pemrosesan Pesanan'],
    ];

    public static function apply(array $snapshot, array $finance): array
    {
        if (! isset($finance['settlement_amount']) || ! is_numeric($finance['settlement_amount'])) {
            return $snapshot;
        }
        $empty = empty($finance['sku_transactions']) && (float) $finance['settlement_amount'] === 0.0;
        foreach (['revenue_amount', 'fee_and_tax_amount', 'shipping_cost_amount'] as $field) {
            $empty = $empty && isset($finance[$field]) && is_numeric($finance[$field]) && (float) $finance[$field] === 0.0;
        }
        if ($empty) {
            if (! empty($snapshot['finance_available']) && ! empty($snapshot['finance_evidence'])) {
                return $snapshot;
            }
            $snapshot['net_income'] = null;
            $snapshot['total_fees'] = null;
            $snapshot['components'] = [];
            $snapshot['component_labels'] = [];
            $snapshot['components_signed'] = true;
            $snapshot['finance_available'] = false;
            $snapshot['finance_schema'] = 'tiktok_202501';

            return $snapshot;
        }
        $components = [];
        $labels = [];
        $groups = ['fee' => 0.0, 'shipping' => 0.0, 'revenue' => 0.0];
        $gross = 0.0;
        $grossCount = 0;
        $add = static function (string $field, float $contribution, string $group) use (&$components, &$labels, &$groups): void {
            [$key,$label] = self::MAP[$field] ?? ['api_'.$field, 'API · '.str_replace('_', ' ', $field)];
            // Components use positive deductions / negative credits throughout the application.
            $components[$key] = ($components[$key] ?? 0) - $contribution;
            $labels[$key] = $label;
            $groups[$group] -= $contribution;
        };
        foreach ($finance['sku_transactions'] ?? [] as $sku) {
            $revenue = $sku['revenue_breakdown'] ?? [];
            if (isset($revenue['subtotal_before_discount_amount']) && is_numeric($revenue['subtotal_before_discount_amount'])) {
                $gross += (float) $revenue['subtotal_before_discount_amount'];
                $grossCount++;
            }
            foreach ($revenue as $field => $value) {
                if ($field !== 'subtotal_before_discount_amount' && is_numeric($value)) {
                    $add($field, (float) $value, 'revenue');
                }
            }
            foreach ($sku['shipping_cost_breakdown'] ?? [] as $field => $value) {
                if (is_numeric($value)) {
                    $add($field, (float) $value, 'shipping');
                }
            }
            foreach (['fee', 'tax'] as $group) {
                foreach ($sku['fee_tax_breakdown'][$group] ?? [] as $field => $value) {
                    // Reference-only affiliate amount before withholding must not be charged again.
                    if (in_array($field, ['affiliate_commission_amount_before_pit', 'affiliate_commission_before_pit_amount'], true) || ! is_numeric($value)) {
                        continue;
                    }
                    $add($field, (float) $value, 'fee');
                }
            }
        }
        $snapshot['finance_order_gross'] = $grossCount > 0 ? $gross : null;
        if ($grossCount > 0 && $grossCount === count($finance['sku_transactions'] ?? [])) {
            $snapshot['gross_amount'] = $gross;
        }
        foreach (['fee_and_tax_amount' => ['fee', 'marketplace_fee_tax', 'Biaya dan Pajak Marketplace Belum Terinci'], 'shipping_cost_amount' => ['shipping', 'shipping_cost', 'Biaya Pengiriman Bersih Belum Terinci']] as $field => [$group,$key,$label]) {
            if (! isset($finance[$field]) || ! is_numeric($finance[$field])) {
                continue;
            }
            $remainder = round(-(float) $finance[$field] - $groups[$group], 2);
            if (abs($remainder) >= 0.005 || ! $components) {
                $components[$key] = $remainder;
                $labels[$key] = $label;
            }
        }
        $snapshot['net_income'] = (float) $finance['settlement_amount'];
        $snapshot['finance_available'] = true;
        $snapshot['finance_evidence'] = true;
        $snapshot['finance_schema'] = 'tiktok_202501';
        $snapshot['finance_revenue'] = isset($finance['revenue_amount']) ? (float) $finance['revenue_amount'] : null;
        $snapshot['components'] = $components;
        $snapshot['component_labels'] = $labels;
        $snapshot['components_signed'] = true;
        $snapshot['total_fees'] = isset($snapshot['gross_amount']) ? round((float) $snapshot['gross_amount'] - $snapshot['net_income'], 2) : null;

        return $snapshot;
    }

    public static function applyUnsettled(array $snapshot, array $transaction): array
    {
        // Confirmed statement evidence always takes priority over a later estimate.
        if (($snapshot['finance_schema'] ?? '') === 'tiktok_202501' && ! empty($snapshot['finance_evidence']) && ($snapshot['is_estimated'] ?? null) === false) {
            return $snapshot;
        }
        foreach (['est_settlement_amount', 'est_revenue_amount', 'est_fee_tax_amount', 'est_shipping_cost_amount'] as $key) {
            if (! isset($transaction[$key]) || ! is_numeric($transaction[$key])) {
                return $snapshot;
            }
        }
        $snapshot['gross_amount'] = (float) $transaction['est_revenue_amount'];
        $normalized = ['order_id' => $transaction['order_id'], 'settlement_amount' => $transaction['est_settlement_amount'], 'revenue_amount' => $transaction['est_revenue_amount'], 'fee_and_tax_amount' => $transaction['est_fee_tax_amount'], 'shipping_cost_amount' => $transaction['est_shipping_cost_amount'], 'sku_transactions' => [['fee_tax_breakdown' => $transaction['fee_tax_breakdown'] ?? [], 'shipping_cost_breakdown' => $transaction['shipping_cost_breakdown'] ?? []]]];
        $snapshot = self::apply($snapshot, $normalized);
        $snapshot['finance_schema'] = 'tiktok_202507_unsettled';
        $snapshot['finance_available'] = true;
        $snapshot['finance_evidence'] = true;
        $snapshot['is_estimated'] = true;
        $snapshot['finance_subtotal_before_discount'] = isset($transaction['revenue_breakdown']['subtotal_before_discount_amount']) ? (float) $transaction['revenue_breakdown']['subtotal_before_discount_amount'] : null;
        $snapshot['finance_seller_discount'] = isset($transaction['revenue_breakdown']['seller_discount_amount']) ? (float) $transaction['revenue_breakdown']['seller_discount_amount'] : null;
        $snapshot['unsettled_reason'] = (string) ($transaction['unsettled_reason'] ?? '');
        $snapshot['estimated_settlement'] = (string) ($transaction['estimated_settlement'] ?? '');

        return $snapshot;
    }

    /**
     * Normalizes real dynamic Shopee /api/v2/payment/get_escrow_detail response.
     * PRD Section 4 & 9: Real Shopee API deductions, anti-double-counting, exact reconciliation.
     */
    public static function applyShopeeEscrow(array $snapshot, array $escrow): array
    {
        $income = $escrow['order_income'] ?? $escrow['response']['order_income'] ?? $escrow;
        if (! isset($income['escrow_amount']) || ! is_numeric($income['escrow_amount'])) {
            return $snapshot;
        }

        $gross = isset($income['original_cost_of_goods_sold']) && (float) $income['original_cost_of_goods_sold'] > 0
            ? (float) $income['original_cost_of_goods_sold']
            : (isset($income['cost_of_goods_sold']) && (float) $income['cost_of_goods_sold'] > 0
                ? (float) $income['cost_of_goods_sold']
                : (float) ($income['buyer_total_amount'] ?? $snapshot['gross_amount'] ?? 0));

        $netIncome = (float) $income['escrow_amount'];
        $totalFees = round($gross - $netIncome, 2);

        $components = [];
        $labels = [];

        // 1. Transaction Fee breakdown or aggregate
        if (! empty($income['transaction_fee_list']) && is_array($income['transaction_fee_list'])) {
            foreach ($income['transaction_fee_list'] as $idx => $tf) {
                $amt = (float) ($tf['fee_amount'] ?? $tf['amount'] ?? 0);
                if (abs($amt) >= 0.005) {
                    $key = 'shopee_tf_'.$idx;
                    $components[$key] = $amt;
                    $labels[$key] = (string) ($tf['fee_name'] ?? 'Biaya Transaksi / Administrasi');
                }
            }
        } else {
            if (! empty($income['administration_fee']) && (float) $income['administration_fee'] > 0) {
                $components['administration_fee'] = (float) $income['administration_fee'];
                $labels['administration_fee'] = 'Biaya Administrasi';
            }
            if (! empty($income['order_processing_fee']) && (float) $income['order_processing_fee'] > 0) {
                $components['order_processing_fee'] = (float) $income['order_processing_fee'];
                $labels['order_processing_fee'] = 'Biaya Proses Pesanan';
            }
            if (! empty($income['transaction_fee']) && (float) $income['transaction_fee'] > 0) {
                $components['transaction_fee'] = (float) $income['transaction_fee'];
                $labels['transaction_fee'] = 'Biaya Pembayaran / Transaksi';
            }
        }

        // 2. Service Fee breakdown or aggregate
        if (! empty($income['service_fee_list']) && is_array($income['service_fee_list'])) {
            foreach ($income['service_fee_list'] as $idx => $sf) {
                $amt = (float) ($sf['service_fee'] ?? $sf['fee_amount'] ?? 0);
                if (abs($amt) >= 0.005) {
                    $key = 'shopee_sf_'.$idx;
                    $components[$key] = $amt;
                    $labels[$key] = (string) ($sf['service_fee_name'] ?? 'Biaya Layanan Shopee');
                }
            }
        } else {
            if (! empty($income['fsf_seller_protection_fee_prorata']) && (float) $income['fsf_seller_protection_fee_prorata'] > 0) {
                $components['fsf_protection_fee'] = (float) $income['fsf_seller_protection_fee_prorata'];
                $labels['fsf_protection_fee'] = 'Biaya Gratis Ongkir XTRA';
            }
            if (! empty($income['service_fee']) && (float) $income['service_fee'] > 0) {
                $components['service_fee'] = (float) $income['service_fee'];
                $labels['service_fee'] = 'Biaya Layanan Shopee';
            }
        }

        // 3. Commission Fee
        if (! empty($income['commission_fee']) && (float) $income['commission_fee'] > 0) {
            $components['commission_fee'] = (float) $income['commission_fee'];
            $labels['commission_fee'] = 'Komisi Marketplace Shopee';
        }

        // 4. Campaign & AMS / Affiliate Fee
        if (! empty($income['campaign_fee']) && (float) $income['campaign_fee'] > 0) {
            $components['campaign_fee'] = (float) $income['campaign_fee'];
            $labels['campaign_fee'] = 'Biaya Kampanye & Promosi';
        }
        if (! empty($income['order_ams_commission_fee']) && (float) $income['order_ams_commission_fee'] > 0) {
            $components['affiliate_commission'] = (float) $income['order_ams_commission_fee'];
            $labels['affiliate_commission'] = 'Komisi Affiliate Shopee';
        }

        // 5. Withholding Tax & VAT
        if (! empty($income['withholding_tax']) && (float) $income['withholding_tax'] > 0) {
            $components['withholding_tax'] = (float) $income['withholding_tax'];
            $labels['withholding_tax'] = 'Pajak Pemotongan PPh';
        }
        if (! empty($income['final_product_vat_tax']) && (float) $income['final_product_vat_tax'] > 0) {
            $components['vat_tax'] = (float) $income['final_product_vat_tax'];
            $labels['vat_tax'] = 'PPN Produk';
        }

        // 6. Shipping Variance: actual vs rebate vs buyer
        if (! empty($income['actual_shipping_fee']) && (float) $income['actual_shipping_fee'] > 0) {
            $components['actual_shipping'] = (float) $income['actual_shipping_fee'];
            $labels['actual_shipping'] = 'Estimasi Ongkir ke Jasa Kirim';
        }
        if (! empty($income['shopee_shipping_rebate']) && (float) $income['shopee_shipping_rebate'] > 0) {
            $components['shipping_subsidy'] = -(float) $income['shopee_shipping_rebate'];
            $labels['shipping_subsidy'] = 'Estimasi Potongan Ongkir dari Shopee';
        }
        if (! empty($income['buyer_paid_shipping_fee']) && (float) $income['buyer_paid_shipping_fee'] > 0) {
            $components['buyer_shipping'] = -(float) $income['buyer_paid_shipping_fee'];
            $labels['buyer_shipping'] = 'Ongkir Dibayar Pembeli';
        }

        // 7. Seller Discounts / Vouchers
        $sellerVoucher = (float) ($income['voucher_from_seller'] ?? $income['seller_discount'] ?? 0);
        if ($sellerVoucher > 0) {
            $components['seller_voucher'] = $sellerVoucher;
            $labels['seller_voucher'] = 'Diskon / Voucher Penjual';
        }

        // 8. Order Adjustment (if any)
        if (! empty($income['order_adjustment']) && is_numeric($income['order_adjustment'])) {
            $adj = (float) $income['order_adjustment'];
            if (abs($adj) >= 0.005) {
                $components['order_adjustment'] = -$adj;
                $labels['order_adjustment'] = 'Penyesuaian Pesanan Shopee';
            }
        }

        // 9. Reconciliation remainder check
        $sumComponents = array_sum($components);
        $remainder = round($totalFees - $sumComponents, 2);
        if (abs($remainder) >= 0.005) {
            $components['shopee_other_fees'] = $remainder;
            $labels['shopee_other_fees'] = 'Biaya Layanan Tambahan / Lainnya';
        }

        $snapshot['gross_amount'] = $gross;
        $snapshot['net_income'] = $netIncome;
        $snapshot['total_fees'] = $totalFees;
        $snapshot['components'] = $components;
        $snapshot['component_labels'] = $labels;
        $snapshot['components_signed'] = true;
        $snapshot['finance_available'] = true;
        $snapshot['finance_evidence'] = true;
        $snapshot['finance_schema'] = 'shopee_escrow_v2';
        $snapshot['finance_updated_at'] = date('Y-m-d H:i:s');

        // Calculate net profit if HPP exists
        if (isset($snapshot['total_cogs']) && is_numeric($snapshot['total_cogs'])) {
            $snapshot['net_profit'] = round($netIncome - (float) $snapshot['total_cogs'], 2);
        }

        return $snapshot;
    }
}
