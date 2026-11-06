<?php

namespace App\Services\Marketplace\Integrations;

use App\Exceptions\MarketplaceIntegrationException;
use App\Services\Marketplace\Http\MarketplaceApiOrigin;
use App\Services\Marketplace\Http\MarketplaceHttp;
use App\Services\Marketplace\Http\Signing\ShopeeSigner;
use App\Services\Marketplace\Sync\SyncWindow;

final class ShopeeOrderDataClient
{
    public function __construct(private array $config, private string $shopId, private MarketplaceHttp $http = new MarketplaceHttp) {}

    public function call(string $method, string $path, array $query, string $token, ?array $body = null): array
    {
        if ($path === '/order/202309/orders/search') {
            $end = min(time(), (int) ($body['update_time_lt'] ?? $body['create_time_lt'] ?? time()));
            $from = (int) ($body['update_time_ge'] ?? $body['create_time_ge'] ?? SyncWindow::checkpoint()['window_start']);
            $cursor = '';
            if (! empty($query['page_token'])) {
                $page = json_decode(base64_decode((string) $query['page_token'], true) ?: '', true);
                if (! is_array($page) || ! isset($page['from'],$page['cursor'])) {
                    throw new MarketplaceIntegrationException('Cursor tidak valid.', 'SHOPEE_CURSOR_INVALID');
                }$from = (int) $page['from'];
                $cursor = (string) $page['cursor'];
            }
            $sliceEnd = min($end, $from + 7 * 86400);
            $params = ['time_range_field' => isset($body['update_time_ge']) ? 'update_time' : 'create_time', 'time_from' => $from, 'time_to' => $sliceEnd, 'page_size' => 20, 'cursor' => $cursor];
            if (! empty($body['order_status'])) {
                $params['order_status'] = $body['order_status'];
            }
            $data = $this->request('/api/v2/order/get_order_list', $params, $token);
            if (! is_array($data['order_list'] ?? null)) {
                throw new MarketplaceIntegrationException('Daftar order Shopee tidak lengkap.', 'SHOPEE_ORDER_LIST_INVALID');
            }
            $next = '';
            if (! empty($data['more'])) {
                if (empty($data['next_cursor'])) {
                    throw new MarketplaceIntegrationException('Cursor halaman kosong.', 'SHOPEE_CURSOR_INVALID');
                }$next = base64_encode(json_encode(['from' => $from, 'cursor' => (string) $data['next_cursor']]));
            } elseif ($sliceEnd < $end) {
                $next = base64_encode(json_encode(['from' => $sliceEnd, 'cursor' => '']));
            }

            return ['orders' => array_map(static fn ($o) => ['id' => (string) $o['order_sn']], $data['order_list']), 'next_page_token' => $next];
        }
        if ($path === '/api/v2/payment/get_escrow_detail' || $path === '/payment/get_escrow_detail' || str_contains($path, 'get_escrow_detail')) {
            $orderSn = (string) ($query['order_sn'] ?? $query['ids'] ?? $query['id'] ?? '');
            if ($orderSn === '') {
                throw new MarketplaceIntegrationException('Order SN kosong.', 'SHOPEE_ORDER_SN_EMPTY');
            }

            return $this->request('/api/v2/payment/get_escrow_detail', ['order_sn' => $orderSn], $token);
        }
        if ($path !== '/order/202309/orders') {
            throw new MarketplaceIntegrationException('Endpoint tidak didukung.', 'SHOPEE_ORDER_ONLY');
        }
        $data = $this->request('/api/v2/order/get_order_detail', ['order_sn_list' => $query['ids'], 'response_optional_fields' => 'item_list,payment_method,cod,total_amount,tracking_number,buyer_cancel_reason,cancel_by,cancel_reason'], $token);
        if (! is_array($data['order_list'] ?? null)) {
            throw new MarketplaceIntegrationException('Rincian order Shopee tidak lengkap.', 'SHOPEE_ORDER_DETAIL_INVALID');
        }
        $orders = [];
        foreach ($data['order_list'] as $o) {
            if (! is_array($o['item_list'] ?? null)) {
                throw new MarketplaceIntegrationException('Produk pesanan kosong.', 'SHOPEE_ORDER_ITEMS_MISSING');
            }
            $lines = [];
            foreach ($o['item_list'] as $i) {
                $lines[] = ['sku_id' => (string) $i['item_id'].':'.(string) ($i['model_id'] ?? 0), 'seller_sku' => ($i['model_sku'] ?? '') ?: ($i['item_sku'] ?? ''), 'product_name' => $i['item_name'] ?? '', 'sku_name' => $i['model_name'] ?? '', 'quantity' => $i['model_quantity_purchased'] ?? 1, 'sale_price' => $i['model_discounted_price'] ?? $i['model_original_price'] ?? null, 'sku_image' => $i['image_info']['image_url'] ?? ''];
            }
            $orders[] = ['id' => (string) $o['order_sn'], 'create_time' => $o['create_time'], 'update_time' => $o['update_time'] ?? $o['create_time'], 'status' => $o['order_status'], 'is_cod' => array_key_exists('cod', $o) ? (bool) $o['cod'] : null, 'payment_method_name' => $o['payment_method'] ?? '', 'tracking_number' => $o['tracking_number'] ?? '', 'payment' => ['currency' => $o['currency'] ?? 'IDR', 'total_amount' => isset($o['total_amount']) ? $o['total_amount'] : null, 'sub_total' => array_sum(array_map(static fn ($i) => (float) ($i['sale_price'] ?? 0) * (int) $i['quantity'], $lines))], 'line_items' => $lines] + array_intersect_key($o, array_flip(['recipient_address', 'shipping_carrier', 'package_list', 'paid_time', 'completion_time', 'cancel_reason']));
        }

        return ['orders' => $orders];
    }

    private function request(string $path, array $params, string $token): array
    {
        $ts = now()->timestamp;
        $pid = (string) $this->config['partner_id'];
        $query = $params + ['partner_id' => $pid, 'timestamp' => $ts, 'shop_id' => $this->shopId, 'access_token' => $token, 'sign' => ShopeeSigner::sign($pid, (string) $this->config['partner_key'], $path, $ts, $token, $this->shopId)];
        $base = MarketplaceApiOrigin::validate('Shopee', (string) $this->config['api_base_url']);
        $url = $base.$path.'?'.http_build_query($query);
        $response = $this->http->request('GET', $url);
        if (! empty($response['error'])) {
            MarketplaceApiOrigin::shopeeError((string) $response['error']);
        }
        if (! is_array($response['response'] ?? null)) {
            throw new MarketplaceIntegrationException('Respons order tidak lengkap.', 'SHOPEE_ORDER_INVALID');
        }

        return $response['response'];
    }
}
