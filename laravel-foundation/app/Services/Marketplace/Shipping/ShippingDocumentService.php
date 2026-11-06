<?php

namespace App\Services\Marketplace\Shipping;

use App\Exceptions\MarketplaceIntegrationException;
use App\Marketplace\TokenVault;
use App\Services\Marketplace\Concerns\RunsSql;
use App\Services\Marketplace\Integrations\TikTokDataClient;
use App\Services\Marketplace\MarketplaceScope;
use App\Services\Marketplace\Sync\PartnerSettings;
use App\Services\Marketplace\Sync\TokenRefresher;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Throwable;

class ShippingDocumentService
{
    use RunsSql;

    public const DOCUMENT_TYPE = 'SHIPPING_LABEL_AND_PACKING_SLIP';

    public function __construct(
        protected DatabaseManager $database,
        protected MarketplaceScope $scope,
        protected PartnerSettings $settings,
        protected TokenRefresher $tokens,
        protected TokenVault $vault,
    ) {}

    protected function user(): string
    {
        $id = trim((string) ($this->scope->userId()));
        if ($id === '') {
            throw new DomainException('Silakan login kembali.');
        }

        return $id;
    }

    protected function connection(string $store): array
    {
        $scope = $this->scope;
        $s = $scope->assertStoreAccess($store);
        $this->user();
        if ($s['platform'] !== 'TikTok Shop') {
            throw new DomainException('Konektor PDF resi Shopee belum tersedia. Gunakan Seller Center Shopee.');
        }
        $connection = fn (): ?array => $this->row("SELECT * FROM marketplace_oauth_connections WHERE store_id=? AND company_key=? AND status='verified'", [$store, $scope->companyKey()]);
        $c = $connection();
        if (! $c) {
            throw new DomainException('Toko perlu diotorisasi ulang sebelum mengambil resi.');
        }
        if (! empty($c['token_expires_at']) && strtotime($c['token_expires_at']) <= time() + 30) {
            $this->tokens->refresh($store, false);
            $c = $connection();
        }

        return $c;
    }

    protected function call(array $c, string $path, array $query, string $method = 'GET', ?array $body = null): array
    {
        $config = $this->settings->configFor('TikTok Shop');
        if (parse_url($config['api_base_url'], PHP_URL_HOST) !== 'open-api.tiktokglobalshop.com') {
            throw new DomainException('Host API TikTok Shop tidak valid.');
        }

        return (new TikTokDataClient($config))->call($method, $path, ['shop_cipher' => $c['external_shop_cipher']] + $query, $this->vault->decrypt($c['access_token_encrypted']), $body);
    }

    /** Fetches the PDF behind a provider link; overridden in tests. */
    protected function download(string $url): string
    {
        return self::fetchPdf($url);
    }

    public static function packages(array $raw): array
    {
        $out = [];
        foreach ($raw['packages'] ?? [] as $p) {
            $id = (string) ($p['id'] ?? $p['package_id'] ?? '');
            if (preg_match('/^[a-zA-Z0-9_-]{1,120}$/', $id)) {
                $out[$id] = $id;
            }
        }
        foreach ($raw['line_items'] ?? [] as $line) {
            $id = (string) ($line['package_id'] ?? '');
            if (preg_match('/^[a-zA-Z0-9_-]{1,120}$/', $id)) {
                $out[$id] = $id;
            }
        }

        return array_values($out);
    }

    protected function audit(string $store, string $order, string $package, string $event, ?string $code = null): void
    {
        $this->run('INSERT INTO marketplace_shipping_document_audit(company_key,store_id,order_id,package_id,user_key,event_type,error_code,created_at) VALUES(?,?,?,?,?,?,?,NOW())', [$this->scope->companyKey(), $store, $order, $package, $this->user(), $event, $code]);
    }

    public function prepare(string $store, string $order): array
    {
        $c = $this->connection($store);
        $o = $this->row("SELECT o.transaction_detail_json,o.source_order_status,r.payload_json FROM marketplace_orders o LEFT JOIN marketplace_sync_records r ON r.store_id=o.store_id AND r.external_id=o.id AND r.resource_type='order' WHERE o.store_id=? AND o.id=? AND o.source_kind='api' AND (o.internal_state IS NULL OR o.internal_state='active')", [$store, $order]);
        if (! $o) {
            throw new DomainException('Pesanan tidak ditemukan di toko aktif.');
        }
        if (in_array($o['source_order_status'], ['UNPAID', 'CANCELLED', 'Dibatalkan'], true)) {
            throw new DomainException('Pesanan belum dibayar atau sudah dibatalkan. Resi tidak diminta.');
        }
        $raw = json_decode((string) $o['payload_json'], true) ?: [];
        $packages = self::packages($raw);
        if (! $packages) {
            $d = $this->call($c, '/order/202309/orders', ['ids' => $order]);
            $raw = null;
            foreach ($d['orders'] ?? [] as $candidate) {
                if ((string) ($candidate['id'] ?? '') === $order) {
                    $raw = $candidate;
                    break;
                }
            }if (! $raw) {
                throw new DomainException('Rincian pesanan terbaru belum tersedia.');
            }if (in_array($raw['status'] ?? '', ['UNPAID', 'CANCELLED'], true)) {
                throw new DomainException('Marketplace menyatakan pesanan belum dibayar atau batal.');
            }
            $packages = self::packages($raw);
        }
        if (! $packages) {
            throw new DomainException('ID paket belum tersedia. Atur pengiriman di Seller Center lalu sinkronkan kembali.');
        }
        if (count($packages) > 10) {
            throw new DomainException('Pesanan memiliki lebih dari 10 paket. Ambil dokumen melalui Seller Center.');
        }
        $company = $this->scope->companyKey();
        $user = $this->user();
        $result = [];
        foreach ($packages as $package) {
            try {
                $ready = $this->row('SELECT id,byte_size,expires_at FROM marketplace_shipping_documents WHERE company_key=? AND store_id=? AND order_id=? AND package_id=? AND user_key=? AND document_type=? AND expires_at>NOW() ORDER BY created_at DESC LIMIT 1', [$company, $store, $order, $package, $user, self::DOCUMENT_TYPE]);
                if (! $ready) {
                    $data = $this->call($c, '/fulfillment/202309/packages/'.rawurlencode($package).'/shipping_documents', ['document_type' => self::DOCUMENT_TYPE, 'document_size' => 'A6', 'document_format' => 'PDF']);
                    $url = $data['doc_url'] ?? null;
                    if (! is_string($url) || $url === '') {
                        throw new MarketplaceIntegrationException('Dokumen belum tersedia dari marketplace.', 'DOCUMENT_NOT_READY');
                    }
                    $pdf = $this->download($url);
                    self::validatePdf($pdf);
                    $id = bin2hex(random_bytes(24));
                    $expires = date('Y-m-d H:i:s', time() + 900);
                    $this->run('INSERT INTO marketplace_shipping_documents(id,company_key,store_id,order_id,package_id,user_key,pdf_encrypted,pdf_hash,byte_size,expires_at,document_type,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW())', [$id, $company, $store, $order, $package, $user, $this->vault->encrypt($pdf), hash('sha256', $pdf), strlen($pdf), $expires, self::DOCUMENT_TYPE]);
                    $ready = ['id' => $id, 'byte_size' => strlen($pdf), 'expires_at' => $expires];
                    $this->audit($store, $order, $package, 'prepared');
                } else {
                    $this->audit($store, $order, $package, 'cache_reused');
                }
                $result[] = ['package_id' => $package, 'success' => true, 'document_id' => $ready['id'], 'document_type' => self::DOCUMENT_TYPE, 'bytes' => (int) $ready['byte_size'], 'expires_at' => $ready['expires_at']];
            } catch (Throwable $e) {
                $code = $e instanceof MarketplaceIntegrationException ? $e->errorCode : 'DOCUMENT_FETCH_FAILED';
                if (str_starts_with($code, 'HTTP_') && preg_match('/Code: (\d{1,12})/', $e->getMessage(), $match)) {
                    $code = 'TIKTOK_API_'.$match[1];
                }
                $this->audit($store, $order, $package, 'failed', $code);
                $result[] = ['package_id' => $package, 'success' => false, 'code' => $code, 'message' => self::message($code)];
            }
        }
        $this->run('DELETE FROM marketplace_shipping_documents WHERE company_key=? AND expires_at<NOW() LIMIT 100', [$company]);

        return $result;
    }

    public function read(string $id, bool $preview = false): array
    {
        if (! preg_match('/^[a-f0-9]{48}$/', $id)) {
            throw new DomainException('Dokumen tidak tersedia.');
        }
        $row = $this->row('SELECT * FROM marketplace_shipping_documents WHERE id=? AND company_key=? AND user_key=? AND expires_at>NOW()', [$id, $this->scope->companyKey(), $this->user()]);
        if (! $row) {
            throw new DomainException('Dokumen kedaluwarsa atau tidak tersedia. Klik Cetak Resi untuk mengambil ulang.');
        }
        $this->scope->assertStoreAccess($row['store_id']);
        $pdf = $this->vault->decrypt($row['pdf_encrypted']);
        self::validatePdf($pdf);
        if (! hash_equals($row['pdf_hash'], hash('sha256', $pdf))) {
            throw new DomainException('Dokumen tidak dapat diverifikasi.');
        }
        $this->audit($row['store_id'], $row['order_id'], $row['package_id'], $preview ? 'preview_opened' : 'downloaded');

        return ['pdf' => $pdf, 'filename' => 'resi-'.preg_replace('/[^a-zA-Z0-9_-]/', '', $row['order_id']).'-'.preg_replace('/[^a-zA-Z0-9_-]/', '', $row['package_id']).'.pdf'];
    }

    public static function validatePdf(string $pdf): void
    {
        if (strlen($pdf) > 8388608 || ! str_starts_with($pdf, '%PDF-') || ! str_contains(substr($pdf, -2048), '%%EOF')) {
            throw new MarketplaceIntegrationException('Respons bukan dokumen PDF yang lengkap.', 'INVALID_LABEL_PDF');
        }
    }

    public static function message(string $code): string
    {
        return match ($code) {
            'TIKTOK_API_21023035','TIKTOK_API_21042104' => 'Pengiriman belum diatur. Klik Atur Pengiriman untuk memilih pickup atau drop-off di Jago Laba.','TIKTOK_API_21042102','TIKTOK_API_21023046' => 'Dokumen tidak dapat diambil untuk paket yang sudah di-pickup atau dikirim.','TIKTOK_API_21023059' => 'Paket sudah dibatalkan.','TIKTOK_API_11034002','TIKTOK_API_21008017' => 'Label ini hanya tersedia untuk TikTok Shipping. Seller Shipping menggunakan dokumen kurir sendiri.','TIKTOK_API_11034037','TIKTOK_API_11034023','DOCUMENT_NOT_READY' => 'Label sedang disiapkan penyedia logistik. Tunggu beberapa saat lalu coba lagi.','HTTP_401','HTTP_403','TIKTOK_API_105005' => 'Akses dokumen ditolak. Periksa izin Fulfillment dan otorisasi toko.','INVALID_LABEL_PDF' => 'Marketplace belum memberikan PDF yang lengkap. Coba lagi.',default => 'PDF resi belum dapat diambil. Coba lagi atau periksa paket di Seller Center. Kode: '.$code
        };
    }

    public static function fetchPdf(string $url): string
    {
        for ($hop = 0; $hop < 4; $hop++) {
            $parts = parse_url($url);
            if (! $parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || (($parts['port'] ?? 443) !== 443)) {
                throw new MarketplaceIntegrationException('Alamat dokumen ditolak.', 'LABEL_URL_INVALID');
            }
            $host = $parts['host'] ?? '';
            if (! preg_match('/^[a-zA-Z0-9.-]+$/', $host)) {
                throw new MarketplaceIntegrationException('Alamat dokumen ditolak.', 'LABEL_URL_INVALID');
            }
            $ips = gethostbynamel($host);
            if (! $ips) {
                throw new MarketplaceIntegrationException('Server dokumen belum dapat dihubungi.', 'LABEL_DNS_FAILED');
            }foreach ($ips as $ip) {
                if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    throw new MarketplaceIntegrationException('Alamat dokumen ditolak.', 'LABEL_URL_INVALID');
                }
            }
            $body = '';
            $location = '';
            $tooLarge = false;
            $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 30, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_RESOLVE => [$host.':443:'.$ips[0]], CURLOPT_HTTPHEADER => ['Accept: application/pdf'], CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$location) {
                if (stripos($line, 'Location:') === 0) {
                    $location = trim(substr($line, 9));
                }

                return strlen($line);
            }, CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (&$body, &$tooLarge) {
                if (strlen($body) + strlen($chunk) > 8388608) {
                    $tooLarge = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            }]);
            $ok = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            if ($tooLarge) {
                throw new MarketplaceIntegrationException('Dokumen terlalu besar.', 'LABEL_TOO_LARGE');
            }if ($status >= 300 && $status < 400 && $location !== '') {
                if (str_starts_with($location, '/')) {
                    $location = 'https://'.$host.$location;
                }
                $url = $location;

                continue;
            }if ($ok === false || $status !== 200) {
                throw new MarketplaceIntegrationException('PDF belum dapat diunduh dari penyedia dokumen.', 'LABEL_DOWNLOAD_FAILED');
            }self::validatePdf($body);

            return $body;
        }throw new MarketplaceIntegrationException('Pengalihan dokumen terlalu banyak.', 'LABEL_REDIRECT_LIMIT');
    }
}
