<?php

namespace App\Marketplace;

/**
 * Application policy for integration health. It is never a claim about a platform's official quota.
 */
final class IntegrationPolicy
{
    /**
     * @return array<string, array{label: string, job_types: list<string>, supported: bool}>
     */
    public static function resources(string $platform): array
    {
        $tiktok = $platform === 'TikTok Shop';

        return [
            'products' => ['label' => 'Produk & varian', 'job_types' => ['products', 'variants'], 'supported' => true],
            'prices' => ['label' => 'Harga (baca)', 'job_types' => ['products', 'variants'], 'supported' => true],
            'stock' => ['label' => 'Stok tersedia', 'job_types' => ['products', 'variants'], 'supported' => true],
            'orders' => ['label' => 'Pesanan & status', 'job_types' => ['orders'], 'supported' => true],
            'shipping' => ['label' => 'PDF resi & pengemasan', 'job_types' => [], 'supported' => $tiktok],
            'returns' => ['label' => 'Retur / pembatalan', 'job_types' => ['returns'], 'supported' => $tiktok],
            'finance' => ['label' => 'Rincian keuangan', 'job_types' => ['transactions'], 'supported' => $tiktok],
            'withdrawals' => ['label' => 'Penarikan dana API', 'job_types' => [], 'supported' => false],
            'ads' => ['label' => 'Data iklan API', 'job_types' => [], 'supported' => false],
        ];
    }

    /** Buckets a machine error code: authorization, permission, rate_limit, temporary, unsupported or request. */
    public static function classify(string $code): string
    {
        if (preg_match('/^SHOPEE_(?:ERROR_AUTH|ERROR_TOKEN|ERROR_ACCESS_TOKEN|ERROR_REFRESH_TOKEN)(?:[._]|$)/', $code)) {
            return 'authorization';
        }
        if (preg_match('/^SHOPEE_(?:ERROR_SERVER|ERROR_INTERNAL)(?:[._]|$)/', $code)) {
            return 'temporary';
        }
        if (in_array($code, ['HTTP_401', 'TIKTOK_API_105001', 'TIKTOK_API_105002', 'REAUTHORIZE_REQUIRED', 'INTEGRATION_CONTEXT_CHANGED'], true)) {
            return 'authorization';
        }
        if (in_array($code, ['HTTP_403', 'TIKTOK_API_105005'], true) || preg_match('/(?:PERMISSION|ACCESS_DENIED|SCOPE|CATALOG_REJECTED)/i', $code)) {
            return 'permission';
        }
        if ($code === 'HTTP_429' || preg_match('/(?:RATE_LIMIT|TOO_MANY)/i', $code)) {
            return 'rate_limit';
        }
        if (preg_match('/^HTTP_5\d\d$/', $code) || in_array($code, ['HTTP_TRANSPORT_ERROR', 'DOCUMENT_NOT_READY'], true)) {
            return 'temporary';
        }
        if (str_contains($code, 'UNSUPPORTED') || str_contains($code, 'NOT_SUPPORTED')) {
            return 'unsupported';
        }

        return 'request';
    }
}
