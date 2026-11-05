<?php

namespace App\Services\Marketplace;

use App\Exceptions\ProviderConnectorInactive;
use DomainException;
use Illuminate\Database\DatabaseManager;

/**
 * Shipping arrangement (courier options, pickup/drop-off confirmation) is performed by the marketplace,
 * so it needs the live provider connector, which is disabled. Requests are checked against the company,
 * store and order and then refused without touching any local state. Nothing is invented: no courier,
 * slot or address is returned and no order is marked as shipped.
 */
final class MarketplaceFulfillmentWriteService
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function options(string $companyKey, string $storeId, string $orderId): never
    {
        $this->assertOrderInScope($companyKey, $storeId, $orderId);

        throw new ProviderConnectorInactive('Konektor pengiriman marketplace belum aktif. Atur pengiriman melalui Seller Center.');
    }

    public function confirm(string $companyKey, string $storeId, string $orderId): never
    {
        $this->assertOrderInScope($companyKey, $storeId, $orderId);

        throw new ProviderConnectorInactive('Konektor pengiriman marketplace belum aktif. Status pesanan tidak diubah; atur pengiriman melalui Seller Center.');
    }

    private function assertOrderInScope(string $companyKey, string $storeId, string $orderId): void
    {
        $connection = $this->database->connection();
        $inCompany = $connection->table('marketplace_stores')
            ->where('id', $storeId)->where('company_key', $companyKey)->whereNull('archived_at')->exists();
        if (! $inCompany) {
            throw new DomainException('Toko tidak ditemukan dalam perusahaan aktif.');
        }

        if (! $connection->table('marketplace_orders')->where('id', $orderId)->where('store_id', $storeId)->exists()) {
            throw new DomainException('Pesanan tidak ditemukan di toko aktif.');
        }
    }
}
