<?php

namespace App\Pilots\Marketplace;

use App\Audit\AuditLogger;
use App\Auth\LegacyUser;
use DomainException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Throwable;

final class MarketplaceFulfillmentWriteService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditLogger $audit,
    ) {}

    public function options(LegacyUser $user, string $companyKey, string $storeId, string $orderId, ?string $packageId = null): array
    {
        $connection = $this->database->connection();
        $store = $connection->table('marketplace_stores')
            ->where('id', $storeId)
            ->where('company_key', $companyKey)
            ->first();

        if (! $store) {
            throw new DomainException('Toko tidak ditemukan dalam perusahaan aktif.', 403);
        }

        $order = $connection->table('marketplace_orders')
            ->where('id', $orderId)
            ->where('store_id', $storeId)
            ->first();

        if (! $order) {
            throw new DomainException('Pesanan tidak ditemukan di toko aktif.', 404);
        }

        $token = bin2hex(random_bytes(24));
        $methods = [
            ['id' => 'DROP_OFF', 'name' => 'Drop-off ke gerai kurir', 'slots' => []],
            ['id' => 'PICKUP', 'name' => 'Pickup kurir ke lokasi gudang', 'slots' => [
                ['start_time' => time() + 3600, 'end_time' => time() + 10800],
            ]],
        ];

        $pkg = $packageId ?: ('PKG-'.$orderId);

        if (\Illuminate\Support\Facades\Schema::hasTable('marketplace_fulfillment_actions')) {
            $connection->table('marketplace_fulfillment_actions')->updateOrInsert(
                ['company_key' => $companyKey, 'store_id' => $storeId, 'package_id' => $pkg],
                [
                    'order_id' => $orderId,
                    'user_key' => (string) $user->getAuthIdentifier(),
                    'state' => 'offered',
                    'offer_hash' => hash('sha256', $token),
                    'offer_json' => json_encode(['methods' => $methods]),
                    'offer_expires_at' => now()->addMinutes(10),
                    'updated_at' => now(),
                ]
            );
        }

        return [
            [
                'package_id' => $pkg,
                'ready' => false,
                'offer_token' => $token,
                'methods' => $methods,
                'carrier' => 'J&T Express / SiCepat',
                'service' => 'Standar Marketplace',
                'warehouse_address' => 'Gudang Utama Konveksi',
                'affected_orders' => [$orderId],
                'drop_off_point_url' => '',
            ],
        ];
    }

    public function confirm(LegacyUser $user, string $companyKey, string $storeId, string $orderId, string $packageId, string $token, string $method, ?int $slotStart = null): array
    {
        $connection = $this->database->connection();
        $store = $connection->table('marketplace_stores')
            ->where('id', $storeId)
            ->where('company_key', $companyKey)
            ->first();

        if (! $store) {
            throw new DomainException('Toko tidak ditemukan dalam perusahaan aktif.', 403);
        }

        $now = now();
        if (\Illuminate\Support\Facades\Schema::hasTable('marketplace_fulfillment_actions')) {
            $connection->table('marketplace_fulfillment_actions')
                ->where('company_key', $companyKey)
                ->where('store_id', $storeId)
                ->where('package_id', $packageId)
                ->update([
                    'state' => 'confirmed',
                    'updated_at' => $now,
                ]);
        }

        $connection->table('marketplace_orders')
            ->where('id', $orderId)
            ->where('store_id', $storeId)
            ->update([
                'order_status' => 'Terkirim',
                'updated_at' => $now,
            ]);

        $this->audit->record(
            $user,
            'Marketplace',
            'CONFIRM_FULFILLMENT',
            $packageId,
            $orderId,
            null,
            ['method' => $method, 'slot_start' => $slotStart],
            "Konfirmasi pengiriman paket {$packageId} untuk pesanan {$orderId}",
        );

        return [
            'confirmed' => true,
            'order_id' => $orderId,
            'package_id' => $packageId,
            'method' => $method,
            'message' => 'Pengaturan pengiriman berhasil dikonfirmasi ke kurir.',
        ];
    }
}
