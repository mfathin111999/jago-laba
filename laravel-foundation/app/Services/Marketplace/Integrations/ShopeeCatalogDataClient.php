<?php

namespace App\Services\Marketplace\Integrations;

use App\Exceptions\MarketplaceIntegrationException;
use App\Services\Marketplace\Http\MarketplaceApiOrigin;
use App\Services\Marketplace\Http\MarketplaceHttp;
use App\Services\Marketplace\Http\Signing\ShopeeSigner;

final class ShopeeCatalogDataClient
{
    public function __construct(
        private array $config,
        private string $shopId,
        private MarketplaceHttp $http = new MarketplaceHttp,
    ) {}

    public function call(string $method, string $path, array $query, string $token, ?array $body = null): array
    {
        if ($path === '/product/202309/products/search') {
            $cursor = (string) ($query['page_token'] ?? '');
            if ($cursor !== '' && ! ctype_digit($cursor)) {
                $this->invalid('Cursor katalog Shopee tidak valid.');
            }
            $offset = $cursor === '' ? 0 : (int) $cursor;
            $data = $this->request('/api/v2/product/get_item_list', [
                'offset' => $offset, 'page_size' => 20, 'item_status' => 'NORMAL',
            ], $token);
            $items = $data['item'] ?? null;
            if (! is_array($items)) {
                $this->invalid('Daftar katalog Shopee tidak lengkap.');
            }
            $ids = [];
            foreach ($items as $item) {
                if (! is_array($item) || ! ctype_digit((string) ($item['item_id'] ?? ''))) {
                    $this->invalid('Identitas produk Shopee tidak lengkap.');
                }
                $ids[] = ['id' => (string) $item['item_id']] + (isset($item['update_time']) ? ['update_time' => (int) $item['update_time']] : []);
            }
            $next = '';
            if (! array_key_exists('has_next_page', $data)) {
                $this->invalid('Informasi halaman katalog Shopee tidak tersedia.');
            }
            if ($data['has_next_page']) {
                $nextOffset = $data['next_offset'] ?? $data['next'] ?? null;
                if (! $items || $nextOffset === null || ! ctype_digit((string) $nextOffset) || (int) $nextOffset <= $offset) {
                    $this->invalid('Halaman katalog Shopee tidak bergerak maju.');
                }
                $next = (string) $nextOffset;
            }

            return ['products' => $ids, 'next_page_token' => $next];
        }
        if (! preg_match('~^/product/202309/products/([0-9]+)$~', $path, $match)) {
            throw new MarketplaceIntegrationException('Endpoint katalog Shopee tidak didukung.', 'SHOPEE_CATALOG_ENDPOINT');
        }
        $id = $match[1];
        $data = $this->request('/api/v2/product/get_item_base_info', ['item_id_list' => $id], $token);
        $items = $data['item_list'] ?? null;
        if (! is_array($items) || count($items) !== 1 || (string) ($items[0]['item_id'] ?? '') !== $id) {
            $this->invalid('Rincian produk Shopee tidak cocok dengan produk yang diminta.');
        }
        $item = $items[0];
        $status = (string) ($item['item_status'] ?? '');
        if ($status === '') {
            $this->invalid('Status produk Shopee tidak tersedia.');
        }
        $product = [
            'id' => $id, 'title' => (string) ($item['item_name'] ?? ''), 'status' => $status,
            'parent_sku' => (string) ($item['item_sku'] ?? ''), 'update_time' => $item['update_time'] ?? time(),
            'main_images' => [['urls' => $item['image']['image_url_list'] ?? []]], 'skus' => [],
            'source_platform' => 'Shopee',
        ];
        if ($status !== 'NORMAL') {
            return $product;
        }
        if ($product['title'] === '') {
            $this->invalid('Nama produk Shopee tidak tersedia.');
        }
        if (! array_key_exists('has_model', $item)) {
            $this->invalid('Informasi varian produk Shopee tidak tersedia.');
        }
        if ($item['has_model']) {
            $models = $this->request('/api/v2/product/get_model_list', ['item_id' => $id], $token);
            if (! is_array($models['model'] ?? null) || ! $models['model']) {
                $this->invalid('Daftar varian Shopee tidak lengkap.');
            }
            foreach ($models['model'] as $model) {
                if (isset($model['model_status']) && ! in_array($model['model_status'], ['NORMAL', 'MODEL_NORMAL'], true)) {
                    continue;
                }
                $variantName = (string) ($model['model_name'] ?? '');
                if ($variantName === '') {
                    $names = [];
                    foreach ($model['tier_index'] ?? [] as $tier => $option) {
                        $name = $models['tier_variation'][$tier]['option_list'][$option]['option'] ?? null;
                        if (is_string($name)) {
                            $names[] = $name;
                        }
                    }
                    $variantName = implode(' / ', $names);
                }
                $product['skus'][] = $this->variant($id, $model + ['item_sku' => $item['item_sku'] ?? ''], $variantName, false);
            }
            // All models explicitly unavailable: hide this product, retaining historical facts/HPP.
            if (! $product['skus']) {
                $product['status'] = 'UNLIST';
            }
        } else {
            $product['skus'][] = $this->variant($id, $item, '', true);
        }
        $identities = array_column($product['skus'], 'id');
        if (count($identities) !== count(array_unique($identities))) {
            $this->invalid('ID varian Shopee berulang.');
        }

        return $product;
    }

    private function variant(string $itemId, array $source, string $name, bool $single): array
    {
        $modelId = $single ? '0' : (string) ($source['model_id'] ?? '');
        if (! ctype_digit($modelId)) {
            $this->invalid('ID varian Shopee tidak tersedia.');
        }
        $prices = $source['price_info'] ?? [];
        $price = array_is_list($prices) ? ($prices[0] ?? []) : $prices;
        if (isset($price['currency']) && $price['currency'] !== 'IDR') {
            throw new MarketplaceIntegrationException('Mata uang selain IDR belum didukung.', 'CURRENCY_UNSUPPORTED');
        }
        $current = $this->amount($price['current_price'] ?? null);
        $original = $this->amount($price['original_price'] ?? null);
        $stock = $source['stock_info_v2']['summary_info']['total_available_stock'] ?? null;
        if ($stock === null && is_array($source['stock_info'] ?? null) && $source['stock_info']) {
            $total = 0.0;
            foreach ($source['stock_info'] as $row) {
                $qty = $this->amount($row['normal_stock'] ?? $row['current_stock'] ?? null);
                if ($qty === null) {
                    $total = null;
                    break;
                }
                $total += $qty;
            }
            $stock = $total;
        }
        $stock = $this->amount($stock);

        return [
            // Same identity as the Shopee order adapter: item_id:model_id, including model 0.
            'id' => $itemId.':'.$modelId,
            'seller_sku' => (string) ($single ? ($source['item_sku'] ?? '') : (($source['model_sku'] ?? '') ?: ($source['item_sku'] ?? ''))),
            'sales_attributes' => $name === '' ? [] : [['value_name' => $name]],
            'price' => ['currency' => 'IDR', 'sale_price' => $current ?? $original, 'original_price' => $original],
            'inventory' => $stock === null ? [] : [['quantity' => $stock]],
        ];
    }

    private function amount($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
            $this->invalid('Harga atau stok Shopee tidak valid.');
        }

        return (float) $value;
    }

    private function request(string $path, array $params, string $token): array
    {
        $base = MarketplaceApiOrigin::validate('Shopee', (string) ($this->config['api_base_url'] ?? ''));
        $timestamp = now()->timestamp;
        $partner = (string) $this->config['partner_id'];
        $query = $params + ['partner_id' => $partner, 'timestamp' => $timestamp,
            'shop_id' => $this->shopId, 'access_token' => $token,
            'sign' => ShopeeSigner::sign($partner, (string) $this->config['partner_key'], $path, $timestamp, $token, $this->shopId)];
        $url = $base.$path.'?'.http_build_query($query);
        $response = $this->http->request('GET', $url);
        if (! empty($response['error'])) {
            MarketplaceApiOrigin::shopeeError((string) $response['error']);
        }
        if (! is_array($response['response'] ?? null)) {
            $this->invalid('Respons katalog Shopee tidak lengkap.');
        }

        return $response['response'];
    }

    private function invalid(string $message): never
    {
        throw new MarketplaceIntegrationException($message, 'SHOPEE_CATALOG_INVALID');
    }
}
