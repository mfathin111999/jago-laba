<?php

namespace App\Pilots\Marketplace;

use App\Audit\AuditLogger;
use App\Auth\LegacyUser;
use DomainException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

final class MarketplaceStoreWriteService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(LegacyUser $user, string $companyKey, array $data): array
    {
        $companyKey = trim($companyKey);
        if ($companyKey === '' || strlen($companyKey) > 120) {
            throw new RuntimeException('Company scope tidak valid.');
        }

        $platform = trim((string) ($data['platform'] ?? 'Shopee'));
        $storeName = trim((string) ($data['store_name'] ?? ''));
        $identifier = trim((string) ($data['store_identifier'] ?? ''));
        $defaultAccId = ! empty($data['default_account_id']) ? (int) $data['default_account_id'] : null;
        $adminFee = (float) ($data['default_admin_fee_pct'] ?? 11.0);
        $freeShipping = (float) ($data['default_free_shipping_pct'] ?? 4.0);
        $paymentFee = (float) ($data['default_payment_fee_pct'] ?? 1.0);
        $syncMode = trim((string) ($data['sync_mode'] ?? 'API'));
        $status = 'Terputus';

        if (! empty($data['partner_id']) || ! empty($data['app_key']) || ! empty($data['access_token'])) {
            throw new LogicException('Kredensial langsung tidak diterima. Hubungkan toko melalui OAuth resmi.');
        }

        if ($storeName === '') {
            throw new InvalidArgumentException('Nama Toko wajib diisi.');
        }

        $connection = $this->database->connection();

        return $connection->transaction(function () use (
            $connection, $user, $companyKey, $platform, $storeName, $identifier,
            $defaultAccId, $adminFee, $freeShipping, $paymentFee, $syncMode, $status, $data
        ): array {
            $rawId = trim((string) ($data['id'] ?? ''));
            if ($rawId === '') {
                $prefix = strtoupper(str_replace(' ', '', $platform));
                $cleanName = strtoupper((string) preg_replace('/[^A-Z0-9]/', '', $storeName));
                $rawId = 'STR-'.substr($prefix, 0, 6).'-'.substr($cleanName, 0, 8);
            }

            $count = $connection->table('marketplace_stores')->where('id', $rawId)->count();
            if ($count > 0) {
                $rawId .= '-'.random_int(10, 99);
            }

            $now = now();
            $connection->table('marketplace_stores')->insert([
                'id' => $rawId,
                'company_key' => $companyKey,
                'platform' => $platform,
                'store_name' => $storeName,
                'store_identifier' => $identifier ?: strtolower(str_replace(' ', '_', $storeName)),
                'default_account_id' => $defaultAccId,
                'default_admin_fee_pct' => $adminFee,
                'default_free_shipping_pct' => $freeShipping,
                'default_payment_fee_pct' => $paymentFee,
                'sync_mode' => $syncMode,
                'status' => $status,
                'authorization_status' => 'pending',
                'total_transactions' => 0,
                'last_synced_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->audit->record(
                $user,
                'Marketplace',
                'CREATE_STORE',
                $rawId,
                $rawId,
                null,
                [
                    'id' => $rawId,
                    'company_key' => $companyKey,
                    'platform' => $platform,
                    'store_name' => $storeName,
                ],
                "Tambah toko marketplace baru: {$storeName} ({$platform})",
            );

            return [
                'success' => true,
                'store_id' => $rawId,
                'store_name' => $storeName,
                'platform' => $platform,
                'message' => "Toko '{$storeName}' ({$platform}) berhasil ditambahkan ke sistem Jago Laba.",
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(LegacyUser $user, string $companyKey, string $storeId, array $data): array
    {
        $companyKey = trim($companyKey);
        $storeId = trim($storeId);
        if ($companyKey === '' || strlen($companyKey) > 120) {
            throw new RuntimeException('Company scope tidak valid.');
        }
        if ($storeId === '' || strlen($storeId) > 50) {
            throw new RuntimeException('Store ID tidak valid.');
        }

        $platform = trim((string) ($data['platform'] ?? 'Shopee'));
        $storeName = trim((string) ($data['store_name'] ?? ''));
        $identifier = trim((string) ($data['store_identifier'] ?? ''));
        $defaultAccId = ! empty($data['default_account_id']) ? (int) $data['default_account_id'] : null;
        $adminFee = (float) ($data['default_admin_fee_pct'] ?? 11.0);
        $freeShipping = (float) ($data['default_free_shipping_pct'] ?? 4.0);
        $paymentFee = (float) ($data['default_payment_fee_pct'] ?? 1.0);
        $syncMode = trim((string) ($data['sync_mode'] ?? 'API'));
        $status = 'Terputus';

        if (isset($data['partner_id']) || isset($data['access_token']) || isset($data['app_key'])) {
            throw new LogicException('Kredensial langsung tidak diterima. Hubungkan toko melalui OAuth resmi.');
        }

        if ($storeName === '') {
            throw new InvalidArgumentException('Nama Toko wajib diisi.');
        }

        $connection = $this->database->connection();

        return $connection->transaction(function () use (
            $connection, $user, $companyKey, $storeId, $platform, $storeName,
            $identifier, $defaultAccId, $adminFee, $freeShipping, $paymentFee, $syncMode, $status
        ): array {
            $store = $connection->table('marketplace_stores')
                ->where('id', $storeId)
                ->where('company_key', $companyKey)
                ->whereNull('archived_at')
                ->lockForUpdate()
                ->first();

            if ($store === null) {
                throw new DomainException('Toko marketplace tidak ditemukan dalam perusahaan aktif.', 403);
            }

            $connection->table('marketplace_stores')
                ->where('id', $storeId)
                ->where('company_key', $companyKey)
                ->update([
                    'platform' => $platform,
                    'store_name' => $storeName,
                    'store_identifier' => $identifier,
                    'default_account_id' => $defaultAccId,
                    'default_admin_fee_pct' => $adminFee,
                    'default_free_shipping_pct' => $freeShipping,
                    'default_payment_fee_pct' => $paymentFee,
                    'sync_mode' => $syncMode,
                    'status' => $status,
                    'updated_at' => now(),
                ]);

            $this->audit->record(
                $user,
                'Marketplace',
                'UPDATE_STORE',
                $storeId,
                $storeId,
                [
                    'platform' => (string) $store->platform,
                    'store_name' => (string) $store->store_name,
                    'store_identifier' => (string) $store->store_identifier,
                ],
                [
                    'platform' => $platform,
                    'store_name' => $storeName,
                    'store_identifier' => $identifier,
                ],
                "Detail toko '{$storeName}' berhasil diperbarui.",
            );

            return [
                'success' => true,
                'store_id' => $storeId,
                'message' => "Detail toko '{$storeName}' berhasil diperbarui.",
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function toggleStatus(LegacyUser $user, string $companyKey, string $storeId, string $status): array
    {
        $status = trim($status);
        if ($status === 'Terhubung') {
            throw new LogicException('Status terhubung hanya dapat ditetapkan setelah verifikasi API resmi.');
        }

        $connection = $this->database->connection();

        return $connection->transaction(function () use ($connection, $user, $companyKey, $storeId, $status): array {
            $store = $connection->table('marketplace_stores')
                ->where('id', $storeId)
                ->where('company_key', $companyKey)
                ->whereNull('archived_at')
                ->lockForUpdate()
                ->first();

            if ($store === null) {
                throw new DomainException('Toko marketplace tidak ditemukan dalam perusahaan aktif.', 403);
            }

            $updates = [
                'status' => $status,
                'updated_at' => now(),
            ];
            if ($status === 'Terputus') {
                $updates['authorization_status'] = 'disconnected';
            }

            $connection->table('marketplace_stores')
                ->where('id', $storeId)
                ->where('company_key', $companyKey)
                ->update($updates);

            $this->audit->record(
                $user,
                'Marketplace',
                'TOGGLE_STORE_STATUS',
                $storeId,
                $storeId,
                ['status' => (string) $store->status],
                ['status' => $status],
                "Status toko {$storeId} berhasil diubah menjadi '{$status}'.",
            );

            return [
                'success' => true,
                'store_id' => $storeId,
                'status' => $status,
                'message' => "Status toko {$storeId} berhasil diubah menjadi '{$status}'.",
            ];
        });
    }

    public function archive(LegacyUser $user, string $companyKey, string $storeId): array
    {
        $connection = $this->database->connection();

        return $connection->transaction(function () use ($connection, $user, $companyKey, $storeId): array {
            $store = $connection->table('marketplace_stores')
                ->where('id', $storeId)
                ->where('company_key', $companyKey)
                ->lockForUpdate()
                ->first();

            if (! $store || $store->archived_at !== null) {
                throw new DomainException('Toko aktif tidak ditemukan.', 404);
            }

            $userId = (string) ($user->getAuthIdentifier() ?: $user->getUsername() ?: 'system');
            $now = now();

            $connection->table('marketplace_stores')
                ->where('id', $storeId)
                ->where('company_key', $companyKey)
                ->update([
                    'archived_at' => $now,
                    'archived_by' => $userId,
                    'status' => 'Terputus',
                    'authorization_status' => 'disconnected',
                    'updated_at' => $now,
                ]);

            if (\Illuminate\Support\Facades\Schema::hasTable('marketplace_oauth_connections')) {
                $connection->table('marketplace_oauth_connections')
                    ->where('store_id', $storeId)
                    ->where('company_key', $companyKey)
                    ->update([
                        'status' => 'disconnected',
                        'token_expires_at' => null,
                        'updated_at' => $now,
                    ]);
            }

            $this->audit->record(
                $user,
                'Marketplace',
                'ARCHIVE_STORE',
                $storeId,
                $storeId,
                ['status' => (string) $store->status],
                ['status' => 'Terputus', 'archived_at' => $now->toIso8601String()],
                "Toko {$storeId} dihapus dari daftar aktif (diarsipkan).",
            );

            return [
                'success' => true,
                'store_id' => $storeId,
                'message' => 'Toko dihapus dari daftar aktif. Koneksi diputus; riwayat tetap tersimpan.',
            ];
        });
    }

    public function restore(LegacyUser $user, string $companyKey, string $storeId): array
    {
        $connection = $this->database->connection();

        return $connection->transaction(function () use ($connection, $user, $companyKey, $storeId): array {
            $store = $connection->table('marketplace_stores')
                ->where('id', $storeId)
                ->where('company_key', $companyKey)
                ->whereNotNull('archived_at')
                ->lockForUpdate()
                ->first();

            if (! $store) {
                throw new DomainException('Toko arsip tidak ditemukan.', 404);
            }

            $now = now();
            $connection->table('marketplace_stores')
                ->where('id', $storeId)
                ->where('company_key', $companyKey)
                ->update([
                    'archived_at' => null,
                    'archived_by' => null,
                    'status' => 'Terputus',
                    'authorization_status' => 'disconnected',
                    'updated_at' => $now,
                ]);

            $this->audit->record(
                $user,
                'Marketplace',
                'RESTORE_STORE',
                $storeId,
                $storeId,
                ['archived_at' => (string) $store->archived_at],
                ['archived_at' => null, 'status' => 'Terputus'],
                "Toko {$storeId} dipulihkan dari arsip.",
            );

            return [
                'success' => true,
                'store_id' => $storeId,
                'message' => 'Toko kembali ke daftar. Hubungkan ulang lewat otorisasi resmi untuk sinkronisasi.',
            ];
        });
    }
}
