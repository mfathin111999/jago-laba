<?php

namespace App\Services\Marketplace\Sync;

use App\Exceptions\MarketplaceIntegrationException;
use App\Marketplace\TokenVault;
use App\Services\Marketplace\Http\MarketplaceHttp;
use Illuminate\Database\DatabaseManager;
use PDO;
use RuntimeException;
use Throwable;

final class PartnerSettings
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly TokenVault $vault,
        private readonly MarketplaceHttp $http,
    ) {}

    public function status(string $platform): array
    {
        $this->assertPlatform($platform);
        $saved = $this->saved($platform);
        $effective = $this->configFor($platform);
        $configured = $platform === 'Shopee'
            ? trim((string) ($effective['partner_id'] ?? '')) !== '' && trim((string) ($effective['partner_key'] ?? '')) !== ''
            : trim((string) ($effective['app_key'] ?? '')) !== '' && trim((string) ($effective['app_secret'] ?? '')) !== '' && trim((string) ($effective['service_id'] ?? '')) !== '';

        return [
            'platform' => $platform,
            'environment' => $platform === 'Shopee' ? ($effective['region'] ?? 'live') : ($effective['environment'] ?? 'unspecified'),
            'configured' => $configured,
            'source' => $saved === null ? 'environment' : 'dashboard',
            'mode' => $platform === 'Shopee' ? (string) ($effective['region'] ?? 'live') : (string) ($effective['region'] ?? 'row'),
            'partner_id' => $platform === 'Shopee' ? (string) ($effective['partner_id'] ?? '') : '',
            'app_key' => $platform === 'TikTok Shop' ? (string) ($effective['app_key'] ?? '') : '',
            'service_id' => $platform === 'TikTok Shop' ? (string) ($effective['service_id'] ?? '') : '',
            'has_secret' => $platform === 'Shopee' ? trim((string) ($effective['partner_key'] ?? '')) !== '' : trim((string) ($effective['app_secret'] ?? '')) !== '',
            'last_test_status' => $saved['last_test_status'] ?? null,
            'last_tested_at' => $saved['last_tested_at'] ?? null,
        ];
    }

    public function configFor(string $platform): array
    {
        $this->assertPlatform($platform);
        $config = (array) config('services.'.($platform === 'Shopee' ? 'shopee' : 'tiktok_shop'), []);
        $saved = $this->saved($platform);
        if ($saved === null) {
            return $config;
        }
        $settings = $saved['settings'];
        if ($platform === 'Shopee') {
            $mode = (string) $settings['region'];
            $config = array_merge($config, $settings);
            $config['auth_url'] = $mode === 'sandbox' ? 'https://open.sandbox.test-stable.shopee.com/auth' : 'https://open.shopee.com/auth';
            $config['api_base_url'] = $mode === 'sandbox' ? 'https://openplatform.sandbox.test-stable.shopee.sg' : 'https://partner.shopeemobile.com';
        } else {
            $config = array_merge($config, $settings);
            $config['auth_url'] = $settings['region'] === 'us' ? 'https://services.us.tiktokshop.com/open/authorize' : 'https://services.tiktokshop.com/open/authorize';
        }

        return $config;
    }

    public function saveAndTest(string $platform, array $input): array
    {
        $this->assertPlatform($platform);
        if (! $this->vault->configured()) {
            throw new MarketplaceIntegrationException('Kunci enkripsi server belum siap.', 'PARTNER_ENCRYPTION_MISSING');
        }
        $current = $this->configFor($platform);
        if ($platform === 'Shopee') {
            $newMode = trim((string) ($input['mode'] ?? 'live'));
            if ($newMode !== ($current['region'] ?? 'live') && trim((string) ($input['partner_key'] ?? '')) === '') {
                throw new MarketplaceIntegrationException('Saat mengganti lingkungan, masukkan Partner ID dan Partner Key yang sesuai. Kredensial Sandbox tidak dapat dipakai untuk Live.', 'SHOPEE_ENVIRONMENT_CREDENTIALS_REQUIRED');
            }
            $settings = [
                'partner_id' => trim((string) ($input['partner_id'] ?? '')),
                'partner_key' => trim((string) ($input['partner_key'] ?? '')) ?: (string) ($current['partner_key'] ?? ''),
                'region' => $newMode,
                'previous_profiles' => $current['previous_profiles'] ?? [],
            ];
            if (! preg_match('/^[0-9]{1,30}$/', $settings['partner_id']) || strlen($settings['partner_key']) < 8 || strlen($settings['partner_key']) > 500 || ! in_array($settings['region'], ['sandbox', 'live'], true)) {
                throw new MarketplaceIntegrationException('Partner ID, Partner Key, atau mode Shopee belum valid.', 'PARTNER_INPUT_INVALID');
            }
        } else {
            $settings = [
                'app_key' => trim((string) ($input['app_key'] ?? '')),
                'app_secret' => trim((string) ($input['app_secret'] ?? '')) ?: (string) ($current['app_secret'] ?? ''),
                'service_id' => trim((string) ($input['service_id'] ?? ($current['service_id'] ?? ''))),
                'region' => trim((string) ($input['mode'] ?? ($current['region'] ?? 'row'))),
                'environment' => trim((string) ($input['environment'] ?? ($current['environment'] ?? 'unspecified'))),
            ];
            if (! in_array($settings['environment'], ['live', 'development', 'unspecified'], true)) {
                throw new MarketplaceIntegrationException('Lingkungan TikTok tidak valid.', 'PARTNER_INPUT_INVALID');
            }
            if ($settings['app_key'] === '' || strlen($settings['app_key']) > 100 || strlen($settings['app_secret']) < 8 || strlen($settings['app_secret']) > 500 || strlen($settings['service_id']) > 100 || ! in_array($settings['region'], ['row', 'us'], true)) {
                throw new MarketplaceIntegrationException('App Key atau App Secret TikTok Shop belum valid.', 'PARTNER_INPUT_INVALID');
            }
        }
        $this->ensureTable();
        $encrypted = $this->vault->encrypt(json_encode($settings, JSON_THROW_ON_ERROR));
        $statement = $this->pdo()->prepare('INSERT INTO marketplace_partner_settings (platform, settings_encrypted, last_test_status, last_tested_at) VALUES (?, ?, NULL, NULL)
            ON DUPLICATE KEY UPDATE settings_encrypted=VALUES(settings_encrypted), last_test_status=NULL, last_tested_at=NULL');
        $statement->execute([$platform, $encrypted]);

        try {
            $message = $platform === 'Shopee'
                ? $this->testShopee($this->configFor($platform))
                : $this->testTikTok($this->configFor($platform), trim((string) ($input['test_access_token'] ?? '')));
            $result = ['ok' => true, 'message' => $message];
        } catch (Throwable $error) {
            $result = ['ok' => false, 'message' => $error instanceof MarketplaceIntegrationException ? $error->getMessage() : 'Uji API gagal. Periksa kredensial dan lingkungan partner.', 'error_code' => $error instanceof MarketplaceIntegrationException ? $error->errorCode : 'PARTNER_TEST_FAILED'];
        }
        $this->pdo()->prepare('UPDATE marketplace_partner_settings SET last_test_status=?, last_tested_at=NOW() WHERE platform=?')
            ->execute([$result['ok'] ? 'passed' : 'failed', $platform]);

        return ['saved' => true, 'test' => $result, 'status' => $this->status($platform)];
    }

    /** Select Live without ever reusing a test secret; previous profile remains encrypted. */
    public function prepareShopeeLive(): void
    {
        $this->ensureTable();
        $current = $this->configFor('Shopee');
        if (($current['region'] ?? '') === 'live') {
            return;
        }
        $profiles = $current['previous_profiles'] ?? [];
        $profiles[(string) ($current['region'] ?? 'sandbox')] = ['partner_id' => $current['partner_id'] ?? '', 'partner_key' => $current['partner_key'] ?? ''];
        $settings = ['region' => 'live', 'partner_id' => '', 'partner_key' => '', 'previous_profiles' => $profiles];
        $q = $this->pdo()->prepare("INSERT INTO marketplace_partner_settings(platform,settings_encrypted,last_test_status,last_tested_at) VALUES('Shopee',?,NULL,NULL) ON DUPLICATE KEY UPDATE settings_encrypted=VALUES(settings_encrypted),last_test_status=NULL,last_tested_at=NULL");
        $q->execute([$this->vault->encrypt(json_encode($settings, JSON_THROW_ON_ERROR))]);
    }

    private function testShopee(array $config): string
    {
        $path = '/api/v2/public/get_shops_by_partner';
        $timestamp = time();
        $partnerId = (string) $config['partner_id'];
        $query = [
            'partner_id' => $partnerId,
            'timestamp' => $timestamp,
            'sign' => hash_hmac('sha256', $partnerId.$path.$timestamp, (string) $config['partner_key']),
            'page_size' => 1,
        ];
        $response = $this->http->request('GET', rtrim((string) $config['api_base_url'], '/').$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        if (trim((string) ($response['error'] ?? '')) !== '') {
            $code = (string) $response['error'];
            // Show only a short machine code; never echo a platform message or signed URL.
            $safeCode = preg_match('/^[a-zA-Z0-9_.-]{1,80}$/', $code) ? $code : 'SHOPEE_PARTNER_TEST_REJECTED';
            throw new MarketplaceIntegrationException('Shopee menolak uji API (kode: '.$safeCode.'). Periksa pasangan Test Partner ID/Test Partner Key, mode Sandbox, izin endpoint, dan pembatasan IP.', $safeCode);
        }

        return 'API Shopee merespons dengan tanda tangan partner yang valid.';
    }

    private function testTikTok(array $config, string $token): string
    {
        if ($token === '' || strlen($token) > 4000 || preg_match('/[\x00-\x1F\x7F]/', $token)) {
            throw new MarketplaceIntegrationException('Pengaturan tersimpan. Isi access token uji dari TikTok Development Shop untuk menguji API.', 'TIKTOK_TEST_TOKEN_REQUIRED');
        }
        $path = '/authorization/202309/shops';
        $query = ['app_key' => (string) $config['app_key'], 'timestamp' => time()];
        ksort($query);
        $input = $path;
        foreach ($query as $key => $value) {
            $input .= $key.(string) $value;
        }
        $secret = (string) $config['app_secret'];
        $query['sign'] = hash_hmac('sha256', $secret.$input.$secret, $secret);
        $response = $this->http->request('GET', rtrim((string) $config['api_base_url'], '/').$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), ['x-tts-access-token: '.$token]);
        if ((int) ($response['code'] ?? -1) !== 0) {
            throw new MarketplaceIntegrationException('TikTok Shop menolak API. Periksa App Key, App Secret, token uji, dan izin seller.', 'TIKTOK_PARTNER_TEST_REJECTED');
        }

        return 'API TikTok Shop merespons dengan token uji dan tanda tangan aplikasi yang valid.';
    }

    private function saved(string $platform): ?array
    {
        $this->ensureTable();
        $statement = $this->pdo()->prepare('SELECT settings_encrypted, last_test_status, last_tested_at FROM marketplace_partner_settings WHERE platform=?');
        $statement->execute([$platform]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (! $row) {
            return null;
        }
        if (! $this->vault->configured()) {
            return null;
        }
        $settings = json_decode($this->vault->decrypt((string) $row['settings_encrypted']), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($settings)) {
            throw new RuntimeException('Pengaturan partner tidak valid.');
        }
        $row['settings'] = $settings;

        return $row;
    }

    private function ensureTable(): void
    {
        if ($this->database->connection()->getSchemaBuilder()->hasTable('marketplace_partner_settings')) {
            return;
        }
        // The table is created by the Laravel migrations (php artisan migrate); a request never creates schema.
        throw new MarketplaceIntegrationException('Skema pengaturan partner belum dipasang. Jalankan php artisan migrate.', 'PARTNER_SCHEMA_MISSING');
    }

    private function assertPlatform(string $platform): void
    {
        if (! in_array($platform, ['Shopee', 'TikTok Shop'], true)) {
            throw new MarketplaceIntegrationException('Platform tidak dikenal.', 'PARTNER_PLATFORM_INVALID');
        }
    }

    private function pdo(): PDO
    {
        return $this->database->connection()->getPdo();
    }
}
