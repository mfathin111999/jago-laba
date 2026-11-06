<?php

namespace App\Services\Marketplace\Integrations;

use App\Exceptions\MarketplaceIntegrationException;
use App\Services\Marketplace\Http\MarketplaceApiOrigin;
use App\Services\Marketplace\Http\MarketplaceHttp;
use App\Services\Marketplace\Http\Signing\TikTokSigner;

final class TikTokDataClient implements MarketplaceReadAdapter
{
    public function __construct(private array $config, private MarketplaceHttp $http = new MarketplaceHttp) {}

    public function call(string $method, string $path, array $query, string $token, ?array $body = null): array
    {
        $base = MarketplaceApiOrigin::validate('TikTok Shop', (string) ($this->config['api_base_url'] ?? ''));
        $query = array_merge($query, ['app_key' => $this->config['app_key'], 'timestamp' => now()->timestamp]);
        $json = $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR);
        $query['sign'] = self::signature($path, $query, $json, (string) $this->config['app_secret']);
        $response = $this->http->request($method, $base.$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), ['Content-Type: application/json', 'x-tts-access-token: '.$token], $body);
        if (! array_key_exists('code', $response) || (int) $response['code'] !== 0) {
            $code = (string) ($response['code'] ?? 'INVALID');
            if (! preg_match('/^\d{1,12}$/', $code)) {
                $code = 'INVALID';
            }
            $message = match ($code) {
                '17029004' => 'Nama promo sudah digunakan. Gunakan nama promo yang berbeda.',
                '105005' => 'TikTok menolak akses. Periksa izin aplikasi dan token toko.',
                default => 'TikTok menolak permintaan. Periksa data dan aturan endpoint terkait.',
            };
            throw new MarketplaceIntegrationException($message, 'TIKTOK_API_'.$code);
        }
        if (! is_array($response['data'] ?? null)) {
            throw new MarketplaceIntegrationException('Respons data TikTok tidak lengkap.', 'TIKTOK_DATA_INVALID');
        }

        return $response['data'];
    }

    public function catalogPage(array $query, string $token): array
    {
        return $this->call('POST', '/product/202309/products/search', $query, $token, ['status' => 'ACTIVATE']);
    }

    public function product(string $id, array $query, string $token): array
    {
        return $this->call('GET', '/product/202309/products/'.rawurlencode($id), $query, $token);
    }

    public function orderPage(array $query, string $token, array $filter): array
    {
        return $this->call('POST', '/order/202309/orders/search', $query, $token, $filter);
    }

    public function order(string $id, array $query, string $token): array
    {
        return $this->call('GET', '/order/202309/orders', $query + ['ids' => $id], $token);
    }

    public static function signature(string $path, array $query, string $body, string $secret): string
    {
        return TikTokSigner::sign($secret, $path, $query, $body, false);
    }
}
