<?php

namespace App\Pilots\Marketplace;

use App\Auth\LegacyUser;
use App\Exceptions\MarketplaceOAuthException;
use App\Marketplace\OAuth\LegacyClientOAuthProvider;
use App\Marketplace\OAuth\MarketplaceOAuthProvider;
use App\Marketplace\OAuth\SandboxMarketplaceOAuthProvider;
use App\Marketplace\TokenVault;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class MarketplaceOAuthService
{
    /** @var array<string, MarketplaceOAuthProvider> */
    private array $providers = [];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly MarketplaceStoreDeletionService $deletion,
    ) {}

    /** Live mode runs the legacy Shopee / TikTok Shop OAuth clients; everything else stays in the sandbox. */
    public function isLive(): bool
    {
        return strtolower((string) config('jago.oauth.mode', 'sandbox')) === 'live';
    }

    /** Replaces the provider for its platform (used by tests to avoid real network calls). */
    public function registerProvider(MarketplaceOAuthProvider $provider): void
    {
        $this->providers[$provider->platform()] = $provider;
    }

    /** @return list<string> */
    public function platforms(): array
    {
        $platforms = config('jago.oauth.platforms', ['Shopee', 'TikTok Shop', 'Tokopedia', 'Lazada', 'Blibli']);
        $platforms = array_values(array_unique(array_filter(array_map(
            static fn (mixed $platform): string => trim((string) $platform),
            is_array($platforms) ? $platforms : [],
        ))));

        return $this->isLive() ? array_values(array_intersect($platforms, LegacyClientOAuthProvider::PLATFORMS)) : $platforms;
    }

    /** @return array<string, mixed> */
    public function configurationStatus(): array
    {
        if ($this->isLive()) {
            return $this->liveConfigurationStatus();
        }

        $callback = $this->legacyCallbackUrl();
        $platforms = [];

        foreach ($this->platforms() as $platform) {
            $platforms[$platform] = [
                'platform' => $platform,
                'environment' => 'sandbox',
                'ready' => true,
                'missing' => [],
                'label' => 'Sandbox tersedia; provider Live tidak dipanggil',
                'authorization' => 'Stub OAuth untuk uji state, callback, token exchange, refresh, dan scope.',
                'callback_url' => $callback,
            ];
        }

        return [
            'mode' => 'sandbox',
            'provider_live_enabled' => false,
            'credentials_exposed_to_client' => false,
            'platforms' => $platforms,
            'callback_urls' => array_fill_keys($this->platforms(), $callback),
            'legacy_callback_url' => $callback,
            'laravel_callback_url' => route('pilot.marketplace.oauth.callback'),
        ];
    }

    /** @return array<string, mixed> */
    private function liveConfigurationStatus(): array
    {
        $callback = $this->legacyCallbackUrl();
        $platforms = [];

        foreach ($this->platforms() as $platform) {
            $provider = $this->providerFor($platform);
            $missing = $provider instanceof LegacyClientOAuthProvider ? $provider->configurationMissing() : [];
            $environment = $provider instanceof LegacyClientOAuthProvider ? (string) ($provider->context()['environment'] ?? 'live') : 'live';
            $platforms[$platform] = [
                'platform' => $platform,
                'environment' => $environment,
                'ready' => $missing === [],
                'missing' => $missing,
                'label' => $missing === [] ? 'Siap: otorisasi memakai API resmi ('.$environment.')' : 'Belum siap: isi '.implode(', ', $missing).' di .env',
                'authorization' => 'OAuth resmi platform, logika sama dengan aplikasi lama.',
                'callback_url' => $callback,
            ];
        }

        return [
            'mode' => 'live',
            'provider_live_enabled' => true,
            'credentials_exposed_to_client' => false,
            'platforms' => $platforms,
            'callback_urls' => array_fill_keys($this->platforms(), $callback),
            'legacy_callback_url' => $callback,
            'laravel_callback_url' => route('pilot.marketplace.oauth.callback'),
        ];
    }

    /** @return array<string, mixed> */
    public function start(LegacyUser $user, string $companyKey, string $platform, ?string $returnPath = null, ?string $scenario = null): array
    {
        $platform = $this->assertPlatform($platform);
        $this->assertStorage('marketplace_oauth_states');
        $returnPath = $this->safeReturnPath($returnPath);
        $state = bin2hex(random_bytes(32));
        $expiresIn = max(60, (int) config('jago.oauth.state_ttl_seconds', 600));
        $connection = $this->database->connection();

        $connection->table('marketplace_oauth_states')->insert([
            'state_hash' => hash('sha256', $state),
            'company_key' => $companyKey,
            'user_key' => $this->userKey($user),
            'platform' => $platform,
            'return_path' => $returnPath,
            'expires_at' => now()->addSeconds($expiresIn),
            'created_at' => now(),
        ]);

        return [
            'platform' => $platform,
            'environment' => $this->isLive() ? 'live' : 'sandbox',
            'authorization_url' => $this->providerFor($platform)->authorizationUrl($state, $this->legacyCallbackUrl(), $this->isLive() ? null : $scenario),
            'callback_url' => $this->legacyCallbackUrl(),
            'laravel_callback_url' => route('pilot.marketplace.oauth.callback'),
            'expires_in' => $expiresIn,
        ];
    }

    /** @return array<string, mixed> */
    public function callback(LegacyUser $user, string $companyKey, array $callback): array
    {
        $this->assertStorage('marketplace_oauth_states');
        $state = trim((string) ($callback['state'] ?? ''));
        if (! preg_match('/^[a-f0-9]{64}$/D', $state)) {
            throw new MarketplaceOAuthException('Sesi otorisasi tidak valid atau sudah kedaluwarsa.', 'INVALID_OAUTH_STATE');
        }

        $stateRow = $this->consumeState($state, $user, $companyKey, (string) ($callback['platform'] ?? ''));
        if (trim((string) ($callback['error'] ?? '')) !== '' || trim((string) ($callback['code'] ?? '')) === '') {
            throw new MarketplaceOAuthException('Otorisasi dibatalkan.', 'AUTH_CANCELLED');
        }

        $platform = (string) $stateRow->platform;
        $tokens = $this->withRetry(fn (): array => $this->providerFor($platform)->exchangeCode((string) $callback['code'], $callback), 'exchange');
        $shops = $this->normalizeShops($tokens['shops'] ?? []);

        if ($shops === []) {
            throw new MarketplaceOAuthException('Respons platform tidak memuat identitas toko.', 'SHOP_IDENTITY_MISSING');
        }

        if (count($shops) > 1) {
            $pendingId = $this->storePending($user, $companyKey, $stateRow, $platform, $tokens, $shops);

            return [
                'status' => 'choose',
                'platform' => $platform,
                'pending_id' => $pendingId,
                'return_path' => (string) $stateRow->return_path,
                'shops' => $shops,
            ];
        }

        $storeId = $this->persistConnection($companyKey, $platform, $tokens, $shops[0], null, (string) $stateRow->created_at);

        return [
            'status' => 'success',
            'platform' => $platform,
            'store_id' => $storeId,
            'return_path' => (string) $stateRow->return_path,
        ];
    }

    /** @return array<string, mixed> */
    public function pending(LegacyUser $user, string $companyKey, string $pendingId): array
    {
        $this->assertStorage('marketplace_oauth_pending');
        $row = $this->loadPending($user, $companyKey, $pendingId, false);
        $payload = json_decode((string) $row->shops_json, true);

        return [
            'platform' => (string) $row->platform,
            'shops' => is_array($payload) && is_array($payload['shops'] ?? null) ? $payload['shops'] : [],
            'expires_at' => (string) $row->expires_at,
        ];
    }

    /** @return array<string, mixed> */
    public function selectPending(LegacyUser $user, string $companyKey, string $pendingId, string $externalStoreId): array
    {
        $this->assertStorage('marketplace_oauth_pending');
        $this->assertStorage('marketplace_oauth_connections');
        $connection = $this->database->connection();

        return $connection->transaction(function () use ($user, $companyKey, $pendingId, $externalStoreId, $connection): array {
            $row = $this->loadPending($user, $companyKey, $pendingId, true);
            $payload = json_decode((string) $row->shops_json, true);
            $shops = is_array($payload) && is_array($payload['shops'] ?? null) ? $payload['shops'] : [];
            $selected = null;

            foreach ($shops as $shop) {
                if (is_array($shop) && (string) ($shop['id'] ?? '') === $externalStoreId) {
                    $selected = $shop;
                    break;
                }
            }

            if (! is_array($selected)) {
                throw new MarketplaceOAuthException('Toko yang dipilih tidak tersedia dari respons sandbox.', 'SHOP_SELECTION_INVALID');
            }

            $tokens = [
                'access_token' => $this->decrypt((string) $row->access_token_encrypted),
                'refresh_token' => $row->refresh_token_encrypted !== null ? $this->decrypt((string) $row->refresh_token_encrypted) : '',
                'token_expires_at' => (string) $row->token_expires_at,
                'refresh_token_expires_at' => (string) $row->refresh_token_expires_at,
                'granted_scopes' => $this->decodeScopes($row->granted_scopes),
            ];
            $storeId = $this->persistConnection($companyKey, (string) $row->platform, $tokens, $selected, $connection, (string) $row->created_at);

            $connection->table('marketplace_oauth_pending')->where('id', $row->id)->update(['consumed_at' => now()]);

            return [
                'status' => 'success',
                'platform' => (string) $row->platform,
                'store_id' => $storeId,
                'return_path' => (string) $row->return_path,
            ];
        });
    }

    /** @return array<string, mixed> */
    public function refresh(LegacyUser $user, string $companyKey, string $storeId): array
    {
        $this->assertStorage('marketplace_oauth_connections');
        $connection = $this->database->connection();
        $row = null;

        $connection->beginTransaction();
        try {
            $row = $connection->table('marketplace_oauth_connections as c')
                ->join('marketplace_stores as s', function ($join): void {
                    $join->on('s.id', '=', 'c.store_id')->on('s.company_key', '=', 'c.company_key');
                })
                ->where('c.store_id', $storeId)
                ->where('c.company_key', $companyKey)
                ->whereNull('s.archived_at')
                ->select('c.*')
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new MarketplaceOAuthException('Koneksi toko tidak ditemukan dalam company aktif.', 'STORE_FORBIDDEN');
            }

            $refreshToken = $this->decrypt((string) ($row->refresh_token_encrypted ?? ''));
            if ($refreshToken === '') {
                throw new MarketplaceOAuthException('Refresh token tidak tersedia; otorisasi ulang diperlukan.', 'REAUTHORIZE_REQUIRED');
            }

            $tokens = $this->withRetry(fn (): array => $this->providerFor((string) $row->platform)->refreshToken($refreshToken, (string) $row->external_store_id, (string) ($row->external_shop_cipher ?? '')), 'refresh');
            $connection->table('marketplace_oauth_connections')->where('id', $row->id)->update([
                'access_token_encrypted' => $this->encrypt((string) $tokens['access_token']),
                'refresh_token_encrypted' => $this->encrypt((string) $tokens['refresh_token']),
                'token_expires_at' => $tokens['token_expires_at'],
                'refresh_token_expires_at' => $tokens['refresh_token_expires_at'],
                'granted_scopes' => json_encode($tokens['granted_scopes'], JSON_THROW_ON_ERROR),
                'status' => 'verified',
                'last_error_code' => null,
                'verified_at' => now(),
                'updated_at' => now(),
            ]);
            $connection->commit();
        } catch (MarketplaceOAuthException $exception) {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            if ($row !== null) {
                $connection->table('marketplace_oauth_connections')->where('id', $row->id)->update([
                    'status' => 'reauthorize',
                    'last_error_code' => $exception->errorCode,
                    'updated_at' => now(),
                ]);
            }

            throw $exception;
        } catch (Throwable $exception) {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            throw $exception;
        }

        return [
            'status' => 'verified',
            'store_id' => $storeId,
            'platform' => (string) $row->platform,
            'token_expires_at' => $tokens['token_expires_at'],
            'refreshed' => true,
        ];
    }

    /** @return array<string, mixed> */
    public function disconnect(LegacyUser $user, string $companyKey, string $storeId): array
    {
        $this->assertStorage('marketplace_oauth_connections');
        $connection = $this->database->connection();

        return $connection->transaction(function () use ($connection, $storeId, $companyKey): array {
            $connection->table('marketplace_oauth_connections')
                ->where('store_id', $storeId)
                ->where('company_key', $companyKey)
                ->update([
                    'status' => 'disconnected',
                    'access_token_encrypted' => null,
                    'refresh_token_encrypted' => null,
                    'token_expires_at' => null,
                    'refresh_token_expires_at' => null,
                    'updated_at' => now(),
                ]);

            $connection->table('marketplace_stores')
                ->where('id', $storeId)
                ->where('company_key', $companyKey)
                ->update([
                    'status' => 'Terputus',
                    'authorization_status' => 'disconnected',
                    'updated_at' => now(),
                ]);

            return [
                'status' => 'disconnected',
                'store_id' => $storeId,
                'disconnected' => true,
            ];
        });
    }

    public function assertOrigin(?string $origin): void
    {
        $origin = trim((string) $origin);
        if ($origin === '') {
            return;
        }

        $expected = parse_url($this->legacyCallbackUrl());
        $actual = parse_url($origin);
        $expectedOrigin = strtolower(($expected['scheme'] ?? '').'://'.($expected['host'] ?? '').(isset($expected['port']) ? ':'.$expected['port'] : ''));
        $actualOrigin = strtolower(($actual['scheme'] ?? '').'://'.($actual['host'] ?? '').(isset($actual['port']) ? ':'.$actual['port'] : ''));

        if ($expectedOrigin === '' || $expectedOrigin !== $actualOrigin) {
            throw new MarketplaceOAuthException('Domain sesi berbeda dari callback.', 'OAUTH_ORIGIN_MISMATCH');
        }
    }

    public function legacyCallbackUrl(): string
    {
        return route('legacy.marketplace.oauth', ['action' => 'callback']);
    }

    private function consumeState(string $state, LegacyUser $user, string $companyKey, string $callbackPlatform): object
    {
        $connection = $this->database->connection();

        return $connection->transaction(function () use ($connection, $state, $user, $companyKey, $callbackPlatform): object {
            $row = $connection->table('marketplace_oauth_states')
                ->where('state_hash', hash('sha256', $state))
                ->lockForUpdate()
                ->first();

            if ($row === null || (string) $row->company_key !== $companyKey || (string) $row->user_key !== $this->userKey($user) || $row->consumed_at !== null || now()->greaterThanOrEqualTo($row->expires_at)) {
                throw new MarketplaceOAuthException('Sesi otorisasi tidak valid atau sudah kedaluwarsa.', 'INVALID_OAUTH_STATE');
            }

            if ($callbackPlatform !== '' && strcasecmp($callbackPlatform, (string) $row->platform) !== 0) {
                $connection->table('marketplace_oauth_states')->where('id', $row->id)->update(['consumed_at' => now()]);
                throw new MarketplaceOAuthException('Platform callback tidak sesuai dengan state.', 'INVALID_OAUTH_PLATFORM');
            }

            $updated = $connection->table('marketplace_oauth_states')
                ->where('id', $row->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            if ($updated !== 1) {
                throw new MarketplaceOAuthException('Sesi otorisasi sudah digunakan.', 'INVALID_OAUTH_STATE');
            }

            return $row;
        });
    }

    private function storePending(LegacyUser $user, string $companyKey, object $state, string $platform, array $tokens, array $shops): string
    {
        $this->assertStorage('marketplace_oauth_pending');
        $pendingId = bin2hex(random_bytes(24));
        $ttl = max(60, (int) config('jago.oauth.state_ttl_seconds', 600));

        $this->database->connection()->table('marketplace_oauth_pending')->insert([
            'pending_hash' => hash('sha256', $pendingId),
            'company_key' => $companyKey,
            'user_key' => $this->userKey($user),
            'platform' => $platform,
            'access_token_encrypted' => $this->encrypt((string) $tokens['access_token']),
            'refresh_token_encrypted' => $this->encrypt((string) $tokens['refresh_token']),
            'token_expires_at' => $tokens['token_expires_at'],
            'refresh_token_expires_at' => $tokens['refresh_token_expires_at'],
            'granted_scopes' => json_encode($tokens['granted_scopes'], JSON_THROW_ON_ERROR),
            'shops_json' => json_encode(['shops' => $shops], JSON_THROW_ON_ERROR),
            'return_path' => (string) $state->return_path,
            'expires_at' => now()->addSeconds($ttl),
            'created_at' => now(),
        ]);

        return $pendingId;
    }

    private function loadPending(LegacyUser $user, string $companyKey, string $pendingId, bool $lock): object
    {
        if (! preg_match('/^[a-f0-9]{48}$/D', $pendingId)) {
            throw new MarketplaceOAuthException('Pilihan toko tidak valid.', 'INVALID_PENDING_SELECTION');
        }

        $query = $this->database->connection()->table('marketplace_oauth_pending')
            ->where('pending_hash', hash('sha256', $pendingId))
            ->where('company_key', $companyKey)
            ->where('user_key', $this->userKey($user))
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now());

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();
        if ($row === null) {
            throw new MarketplaceOAuthException('Pilihan toko sudah kedaluwarsa atau tidak tersedia.', 'INVALID_PENDING_SELECTION');
        }

        return $row;
    }

    private function persistConnection(string $companyKey, string $platform, array $tokens, array $shop, ?Connection $connection = null, ?string $authorizationStarted = null): string
    {
        $connection ??= $this->database->connection();
        $externalId = trim((string) ($shop['id'] ?? ''));
        $name = trim((string) ($shop['name'] ?? ''));
        if ($externalId === '' || $name === '') {
            throw new MarketplaceOAuthException('Identitas toko sandbox tidak lengkap.', 'SHOP_IDENTITY_MISSING');
        }

        // A store that is being (or was just) erased must not be reconnected by an authorization that began before the erasure.
        try {
            $this->deletion->guardAuthorization($companyKey, $platform, $externalId, $authorizationStarted);
        } catch (\DomainException $exception) {
            throw new MarketplaceOAuthException($exception->getMessage(), 'STORE_BEING_DELETED');
        }

        $store = $connection->table('marketplace_stores')
            ->where('platform', $platform)
            ->where('store_identifier', $externalId)
            ->lockForUpdate()
            ->first();
        if ($store !== null && (string) $store->company_key !== $companyKey) {
            throw new MarketplaceOAuthException('Toko sudah terhubung ke perusahaan lain.', 'STORE_OWNED_BY_OTHER_COMPANY');
        }

        $storeId = $store === null ? $this->createStore($connection, $companyKey, $platform, $externalId, $name) : (string) $store->id;
        $existing = $connection->table('marketplace_oauth_connections')
            ->where('platform', $platform)
            ->where('external_store_id', $externalId)
            ->lockForUpdate()
            ->first();

        $payload = [
            'company_key' => $companyKey,
            'store_id' => $storeId,
            'platform' => $platform,
            'external_store_id' => $externalId,
            'external_shop_cipher' => $shop['cipher'] ?? null,
            'external_store_name' => $name,
            'access_token_encrypted' => $this->encrypt((string) $tokens['access_token']),
            'refresh_token_encrypted' => $this->encrypt((string) $tokens['refresh_token']),
            'token_expires_at' => $tokens['token_expires_at'],
            'refresh_token_expires_at' => $tokens['refresh_token_expires_at'],
            'granted_scopes' => json_encode($tokens['granted_scopes'], JSON_THROW_ON_ERROR),
            'authorized_at' => now(),
            'verified_at' => now(),
            'status' => 'verified',
            'last_error_code' => null,
            'updated_at' => now(),
        ];

        if ($existing !== null) {
            if ((string) $existing->company_key !== $companyKey) {
                throw new MarketplaceOAuthException('Koneksi toko sudah dimiliki perusahaan lain.', 'STORE_OWNED_BY_OTHER_COMPANY');
            }

            $connection->table('marketplace_oauth_connections')->where('id', $existing->id)->update($payload);
        } else {
            $connection->table('marketplace_oauth_connections')->insert($payload + ['created_at' => now()]);
        }

        return $storeId;
    }

    private function createStore(Connection $connection, string $companyKey, string $platform, string $externalId, string $name): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $platform), 0, 6));
        $storeId = 'OAUTH-'.$prefix.'-'.strtoupper(bin2hex(random_bytes(12)));
        $connection->table('marketplace_stores')->insert([
            'id' => $storeId,
            'company_key' => $companyKey,
            'platform' => $platform,
            'store_name' => $name,
            'store_identifier' => $externalId,
            'authorization_status' => 'verified',
            'archived_at' => null,
        ]);

        return $storeId;
    }

    /** @return list<array<string, mixed>> */
    private function normalizeShops(mixed $shops): array
    {
        if (! is_array($shops)) {
            return [];
        }

        $result = [];
        foreach ($shops as $shop) {
            if (! is_array($shop)) {
                continue;
            }

            $id = trim((string) ($shop['id'] ?? ''));
            $name = trim((string) ($shop['name'] ?? ''));
            if ($id !== '' && $name !== '') {
                $result[] = ['id' => $id, 'name' => $name, 'cipher' => $shop['cipher'] ?? null];
            }
        }

        return $result;
    }

    /** @return list<string> */
    private function decodeScopes(mixed $scopes): array
    {
        $decoded = json_decode((string) $scopes, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    private function providerFor(string $platform): MarketplaceOAuthProvider
    {
        return $this->providers[$platform] ??= $this->isLive() ? new LegacyClientOAuthProvider($platform) : new SandboxMarketplaceOAuthProvider($platform);
    }

    private function assertPlatform(string $platform): string
    {
        $platform = trim($platform);
        if ($platform === '' || ! in_array($platform, $this->platforms(), true)) {
            throw new MarketplaceOAuthException('Platform Marketplace tidak didukung oleh sandbox.', 'PLATFORM_UNSUPPORTED');
        }

        return $platform;
    }

    private function assertStorage(string $table): void
    {
        if (! Schema::hasTable($table)) {
            throw new MarketplaceOAuthException('Penyimpanan OAuth belum tersedia.', 'OAUTH_STORAGE_UNAVAILABLE');
        }
    }

    private function userKey(LegacyUser $user): string
    {
        return $user->getLegacyUserId() !== '' ? $user->getLegacyUserId() : $user->getAuthIdentifier();
    }

    private function safeReturnPath(?string $returnPath): string
    {
        $returnPath = trim((string) ($returnPath ?: config('jago.oauth.return_path', 'marketplace/integrations')));
        $parsed = parse_url($returnPath);
        if (($parsed['scheme'] ?? '') !== '' || ($parsed['host'] ?? '') !== '' || ! in_array($parsed['path'] ?? 'marketplace.php', ['marketplace.php', 'marketplace/integrations'], true)) {
            throw new MarketplaceOAuthException('Tujuan kembali tidak diizinkan.', 'RETURN_PATH_INVALID');
        }

        return ltrim($returnPath, '/');
    }

    private function encrypt(string $value): string
    {
        try {
            return (new TokenVault)->encrypt($value);
        } catch (Throwable $exception) {
            throw new MarketplaceOAuthException('Penyimpanan token sandbox belum dikonfigurasi.', 'TOKEN_STORAGE_UNAVAILABLE', $exception);
        }
    }

    private function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        try {
            return (new TokenVault)->decrypt($value);
        } catch (Throwable $exception) {
            throw new MarketplaceOAuthException('Token sandbox tidak dapat dibaca.', 'TOKEN_STORAGE_UNAVAILABLE', $exception);
        }
    }

    /** @return array<string, mixed> */
    private function withRetry(callable $operation, string $operationName): array
    {
        $maxAttempts = max(1, (int) config('jago.oauth.retry.max_attempts', 2));
        $attempt = 0;

        while (++$attempt <= $maxAttempts) {
            try {
                return $operation();
            } catch (MarketplaceOAuthException $exception) {
                $retryable = in_array($exception->errorCode, ['SANDBOX_TIMEOUT', 'SANDBOX_RATE_LIMITED', 'PROVIDER_TIMEOUT', 'PROVIDER_RATE_LIMITED'], true);
                if (! $retryable || $attempt >= $maxAttempts) {
                    $code = $exception->errorCode === 'SANDBOX_TIMEOUT' ? 'PROVIDER_TIMEOUT' : ($exception->errorCode === 'SANDBOX_RATE_LIMITED' ? 'PROVIDER_RATE_LIMITED' : $exception->errorCode);
                    throw new MarketplaceOAuthException($this->isLive() ? 'Permintaan ke platform gagal setelah percobaan ulang.' : 'Permintaan provider sandbox gagal setelah retry.', $code, $exception);
                }
            }
        }

        throw new MarketplaceOAuthException('Operasi provider sandbox gagal.', 'PROVIDER_'.$operationName.'_FAILED');
    }
}
