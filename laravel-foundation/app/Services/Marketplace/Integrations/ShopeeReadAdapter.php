<?php

namespace App\Services\Marketplace\Integrations;

final class ShopeeReadAdapter implements MarketplaceReadAdapter
{
    private ShopeeCatalogDataClient $catalog;

    private ShopeeOrderDataClient $orders;

    public function __construct(array $config, string $shopId)
    {
        $this->catalog = new ShopeeCatalogDataClient($config, $shopId);
        $this->orders = new ShopeeOrderDataClient($config, $shopId);
    }

    // Legacy normalization stays inside the Shopee connector; HTTP always uses Shopee endpoints.
    public function catalogPage(array $query, string $token): array
    {
        return $this->catalog->call('GET', '/product/202309/products/search', $query, $token);
    }

    public function product(string $id, array $query, string $token): array
    {
        return $this->catalog->call('GET', '/product/202309/products/'.rawurlencode($id), $query, $token);
    }

    public function orderPage(array $query, string $token, array $filter): array
    {
        return $this->orders->call('GET', '/order/202309/orders/search', $query, $token, $filter);
    }

    public function order(string $id, array $query, string $token): array
    {
        return $this->orders->call('GET', '/order/202309/orders', $query + ['ids' => $id], $token);
    }

    public function escrowDetail(string $orderSn, string $token): array
    {
        return $this->orders->call('GET', '/api/v2/payment/get_escrow_detail', ['order_sn' => $orderSn], $token);
    }

    public function call(string $method, string $path, array $query, string $token, ?array $body = null): array
    {
        return $this->orders->call($method, $path, $query, $token, $body);
    }
}
