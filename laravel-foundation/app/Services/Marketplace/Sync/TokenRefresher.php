<?php

namespace App\Services\Marketplace\Sync;

use App\Exceptions\MarketplaceIntegrationException;
use App\Exceptions\MarketplaceOAuthException;
use App\Marketplace\OAuth\MarketplaceOAuthProviderFactory;
use App\Marketplace\TokenVault;
use App\Services\Marketplace\MarketplaceScope;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Throwable;

/**
 * Renews the access token of a connected store before a provider call. Used by sync, the price editor and shipping
 * documents (all inside a MarketplaceScope company). The row is locked while the platform is called, so two workers
 * never refresh the same store twice; a rejected refresh marks the store as needing re-authorization.
 */
final class TokenRefresher
{
    /** Failures that mean the stored refresh token can no longer work. */
    private const REAUTHORIZE_CODES = ['HTTP_401', 'TIKTOK_REFRESH_REJECTED', 'SHOPEE_API_REJECTED', 'REAUTHORIZE_REQUIRED'];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly MarketplaceScope $scope,
        private readonly PartnerSettings $settings,
        private readonly IntegrationRuntime $runtime,
        private readonly TokenVault $vault,
        private readonly MarketplaceOAuthProviderFactory $providers,
    ) {}

    /**
     * @param  bool  $force  false: do nothing when another worker already renewed the token
     * @return array{status: string, message: string}
     */
    public function refresh(string $storeId, bool $force = true): array
    {
        $store = $this->scope->assertStoreAccess($storeId);
        $companyKey = $this->scope->companyKey();
        $config = $this->settings->configFor((string) $store['platform']);
        $db = $this->database->connection();

        $db->beginTransaction();
        try {
            $connection = $db->table('marketplace_oauth_connections as c')
                ->join('marketplace_stores as s', function ($join): void {
                    $join->on('s.id', '=', 'c.store_id')->on('s.company_key', '=', 'c.company_key');
                })
                ->where('c.store_id', $storeId)->where('s.company_key', $companyKey)
                ->select('c.*', 's.company_key as store_company_key')
                ->lockForUpdate()
                ->first();
            if ($connection === null) {
                throw new MarketplaceIntegrationException('Koneksi toko tidak ditemukan.', 'CONNECTION_NOT_FOUND');
            }
            $connection = (array) $connection;

            if (! $force && ! empty($connection['token_expires_at']) && strtotime((string) $connection['token_expires_at']) > time() + 60) {
                $db->commit();

                return ['status' => 'verified', 'message' => 'Token sudah diperbarui proses lain.'];
            }

            $this->runtime->assertContext($connection, $config);
            $refreshToken = $connection['refresh_token_encrypted'] ? $this->vault->decrypt((string) $connection['refresh_token_encrypted']) : '';
            if ($refreshToken === '') {
                throw new MarketplaceIntegrationException('Toko perlu dihubungkan ulang.', 'REAUTHORIZE_REQUIRED');
            }

            $tokens = $this->tokens((string) $connection['platform'], $refreshToken, $connection);
            $db->table('marketplace_oauth_connections')->where('id', (int) $connection['id'])->update([
                'access_token_encrypted' => $this->vault->encrypt((string) $tokens['access_token']),
                'refresh_token_encrypted' => ! empty($tokens['refresh_token']) ? $this->vault->encrypt((string) $tokens['refresh_token']) : (string) $connection['refresh_token_encrypted'],
                'token_expires_at' => $tokens['token_expires_at'],
                'refresh_token_expires_at' => $tokens['refresh_token_expires_at'],
                'granted_scopes' => json_encode($tokens['granted_scopes'] ?? []),
                'status' => 'verified',
                'last_error_code' => null,
                'verified_at' => now(),
                'updated_at' => now(),
            ]);
            $db->commit();

            return ['status' => 'verified', 'message' => 'Token toko berhasil diperbarui.'];
        } catch (Throwable $error) {
            if ($db->transactionLevel() > 0) {
                if ($error instanceof MarketplaceIntegrationException && in_array($error->errorCode, self::REAUTHORIZE_CODES, true)) {
                    $db->table('marketplace_oauth_connections')->where('store_id', $storeId)->where('company_key', $companyKey)
                        ->update(['status' => 'reauthorize', 'last_error_code' => $error->errorCode]);
                }
                $db->commit();
            }

            throw $this->safe($error);
        }
    }

    /**
     * Asks the platform for new tokens. The provider reports failures as OAuth exceptions; the original integration
     * error code (HTTP_401, TIKTOK_REFRESH_REJECTED, ...) is restored because callers classify and retry on it.
     *
     * @param  array<string, mixed>  $connection
     * @return array<string, mixed>
     */
    private function tokens(string $platform, string $refreshToken, array $connection): array
    {
        try {
            return $this->providers->for($platform)->refreshToken($refreshToken, (string) $connection['external_store_id'], (string) ($connection['external_shop_cipher'] ?? ''));
        } catch (MarketplaceOAuthException $error) {
            $original = $error->getPrevious();
            throw $original instanceof MarketplaceIntegrationException ? $original : new MarketplaceIntegrationException($error->getMessage(), $error->errorCode);
        }
    }

    private function safe(Throwable $error): Throwable
    {
        if ($error instanceof MarketplaceIntegrationException || $error instanceof InvalidArgumentException) {
            return $error;
        }

        return new MarketplaceIntegrationException('Koneksi toko belum dapat diverifikasi.', 'CONNECTION_NOT_VERIFIED');
    }
}
