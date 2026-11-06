<?php

namespace App\Services\Marketplace\Price;

use App\Exceptions\MarketplaceIntegrationException;
use App\Marketplace\TokenVault;
use App\Services\Marketplace\Analytics\OrderPresentation;
use App\Services\Marketplace\Integrations\TikTokDataClient;
use App\Services\Marketplace\MarketplaceScope;
use App\Services\Marketplace\Sync\PartnerSettings;
use App\Services\Marketplace\Sync\TokenRefresher;
use DomainException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use PDO;
use Throwable;

final class PriceService
{
    public function __construct(private readonly DatabaseManager $database, private readonly PartnerSettings $settings, private readonly TokenRefresher $tokens, private readonly MarketplaceScope $scope, private readonly TokenVault $vault) {}

    private function context(string $store): array
    {
        $scope = $this->scope;
        $shop = $scope->assertStoreAccess($store);
        $stmt = $this->pdo()->prepare("SELECT * FROM marketplace_oauth_connections WHERE store_id=? AND company_key=? AND platform='TikTok Shop' AND status='verified'");
        $stmt->execute([$store, $scope->companyKey()]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($shop['platform'] !== 'TikTok Shop') {
            throw new DomainException('Konektor harga untuk platform ini belum tersedia. Harga katalog terakhir tetap dapat dilihat pada daftar produk.');
        }
        if (! $c) {
            throw new DomainException('Otorisasi toko TikTok Shop belum terverifikasi. Hubungkan atau otorisasi ulang toko melalui Pengaturan > Tambah Toko.');
        }
        if (! empty($c['token_expires_at']) && strtotime($c['token_expires_at']) < time() + 60) {
            $this->tokens->refresh($store, false);
            $stmt->execute([$store, $scope->companyKey()]);
            $c = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (empty($c['external_shop_cipher'])) {
            throw new DomainException('Cipher toko tidak tersedia. Hubungkan ulang toko.');
        }
        $config = $this->settings->configFor('TikTok Shop');

        return ['shop' => $shop, 'connection' => $c, 'client' => new TikTokDataClient($config), 'token' => $this->vault->decrypt($c['access_token_encrypted']), 'query' => ['shop_cipher' => $c['external_shop_cipher']]];
    }

    private function api(array $c, string $method, string $path, ?array $body = null): array
    {
        return $c['client']->call($method, $path, $c['query'], $c['token'], $body);
    }

    private function rows(string $store, string $product): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM marketplace_catalog_variants WHERE store_id=? AND external_product_id=? ORDER BY id');
        $stmt->execute([$store, $product]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (! $rows) {
            throw new DomainException('Produk/varian katalog resmi tidak ditemukan di toko ini. Sinkronkan produk terlebih dahulu.');
        }

        return $rows;
    }

    private function remoteProduct(array $c, string $id): array
    {
        $p = $this->api($c, 'GET', '/product/202309/products/'.rawurlencode($id));
        if ((string) ($p['id'] ?? '') !== $id || ! is_array($p['skus'] ?? null)) {
            throw new MarketplaceIntegrationException('Identitas produk dari API tidak cocok.', 'PRICE_PRODUCT_INVALID');
        }

        return $p;
    }

    private function basePrice(array $sku): ?string
    {
        $price = $sku['price'] ?? [];
        if (isset($price['currency']) && $price['currency'] !== 'IDR') {
            throw new DomainException('Editor Rupiah hanya mendukung produk IDR.');
        }
        $value = $price['tax_exclusive_price'] ?? $price['amount'] ?? $price['sale_price'] ?? null;

        return $value === null ? null : $this->money((string) $value);
    }

    private function money(string $value): string
    {
        if (! preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $value) || (float) $value <= 0) {
            throw new InvalidArgumentException('Harga harus lebih dari 0, tanpa pemisah ribuan, maksimal dua angka desimal.');
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function promotions(array $c, string $product): array
    {
        $result = [];
        foreach (['ONGOING', 'NOT_START'] as $status) {
            $page = '';
            $seen = [];
            do {
                $list = $this->api($c, 'POST', '/promotion/202309/activities/search', ['status' => $status, 'page_size' => 100, 'page_token' => $page]);
                if (! is_array($list['activities'] ?? null)) {
                    if ((int) ($list['total_count'] ?? -1) === 0) {
                        $list['activities'] = [];
                    } else {
                        throw new MarketplaceIntegrationException('Daftar promo tidak lengkap.', 'PROMO_LIST_INVALID');
                    }
                }
                foreach ($list['activities'] as $activity) {
                    if (! in_array($activity['activity_type'] ?? '', ['FIXED_PRICE', 'DIRECT_DISCOUNT', 'FLASHSALE'], true)) {
                        continue;
                    }
                    $id = (string) ($activity['id'] ?? $activity['activity_id'] ?? '');
                    if ($id === '') {
                        throw new MarketplaceIntegrationException('ID promo tidak lengkap.', 'PROMO_LIST_INVALID');
                    }
                    $detail = $this->api($c, 'GET', '/promotion/202309/activities/'.rawurlencode($id));
                    foreach (($detail['products'] ?? []) as $p) {
                        if ((string) ($p['id'] ?? '') === $product) {
                            $result[] = ['id' => $id, 'title' => $detail['title'] ?? $activity['title'] ?? '', 'status' => $detail['status'] ?? $status, 'product_level' => $detail['product_level'] ?? $activity['product_level'] ?? '', 'type' => $detail['activity_type'] ?? $activity['activity_type'], 'begin_time' => $detail['begin_time'] ?? null, 'end_time' => $detail['end_time'] ?? null, 'product' => $p];
                        }
                    }
                }
                $page = (string) ($list['next_page_token'] ?? '');
                if ($page !== '' && isset($seen[$page])) {
                    throw new MarketplaceIntegrationException('Halaman promo berulang.', 'PROMO_PAGINATION_INVALID');
                }$seen[$page] = true;
                if (count($seen) > 20) {
                    throw new MarketplaceIntegrationException('Terlalu banyak halaman promo. Persempit pengaturan di Seller Center.', 'PROMO_LIST_LIMIT');
                }
            } while ($page !== '');
        }

        return $result;
    }

    public function prepare(string $store, string $product): array
    {
        $c = $this->context($store);
        $rows = $this->rows($store, $product);
        $remote = $this->remoteProduct($c, $product);
        $skuMap = [];
        foreach ($remote['skus'] as $sku) {
            $skuMap[(string) $sku['id']] = $sku;
        }
        $promoError = null;
        $promoMessage = null;
        try {
            $promos = $this->promotions($c, $product);
        } catch (Throwable $e) {
            $promos = [];
            $promoError = $e instanceof MarketplaceIntegrationException ? $e->errorCode : 'PROMO_READ_FAILED';
            $promoMessage = $e instanceof MarketplaceIntegrationException ? $e->getMessage() : 'Pembacaan promo gagal.';
        }
        foreach ($rows as &$row) {
            $sku = $skuMap[$row['external_variant_id']] ?? null;
            $row['base_price'] = $sku ? $this->basePrice($sku) : null;
            $row['editable'] = $sku !== null;
            $row['promotions'] = array_values(array_filter($promos, static function ($p) use ($row) {
                return $p['product_level'] === 'PRODUCT' || in_array($row['external_variant_id'], array_map(static fn ($s) => (string) $s['id'], $p['product']['skus'] ?? []), true);
            }));
            $row['promotion_caption'] = self::promotionCaption($row, $promoError !== null);
            $stmt = $this->pdo()->prepare('SELECT unit_hpp FROM marketplace_hpp_history WHERE store_id=? AND marketplace_sku=? AND effective_from<=CURDATE() ORDER BY effective_from DESC,id DESC LIMIT 1');
            $stmt->execute([$store, $row['sku']]);
            $hpp = $stmt->fetchColumn();
            $row['hpp'] = $hpp === false ? null : (float) $hpp;
            $stmt = $this->pdo()->prepare("SELECT DISTINCT o.* FROM marketplace_orders o JOIN marketplace_order_items oi ON oi.order_id=o.id WHERE o.store_id=? AND oi.sku=? AND o.order_date>=DATE_SUB(NOW(),INTERVAL 30 DAY) AND (o.internal_state IS NULL OR o.internal_state='active') AND (SELECT COUNT(DISTINCT sq.sku) FROM marketplace_order_items sq WHERE sq.order_id=o.id)=1");
            $stmt->execute([$store, $row['sku']]);
            $fees = 0.0;
            $gross = 0.0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
                $p = OrderPresentation::build($o);
                if ($p['net_income'] !== null && $p['gross_amount'] > 0 && ! in_array($p['source_status'], ['CANCELLED', 'Dibatalkan'], true)) {
                    $gross += $p['gross_amount'];
                    $fees += $p['total_fees'];
                }
            }
            $row['fee_rate'] = $gross > 0 ? $fees / $gross : null;
        }unset($row);

        return ['store_id' => $store, 'product_id' => $product, 'product_name' => $remote['title'] ?? $rows[0]['product_name'], 'store_name' => $c['shop']['store_name'], 'platform' => 'TikTok Shop', 'rows' => $rows, 'promotion_error' => $promoError, 'promotion_error_message' => $promoMessage, 'product_status' => $remote['status'] ?? null];
    }

    /** Display only prices explicitly returned by the shop, without inferring discounts. */
    public static function promotionCaption(array $row, bool $unavailable = false): string
    {
        if ($unavailable) {
            return 'Data promo belum tersedia';
        }
        $active = [];
        $scheduled = [];
        $unknown = false;
        foreach ($row['promotions'] ?? [] as $promo) {
            $sku = null;
            foreach ($promo['product']['skus'] ?? [] as $candidate) {
                if ((string) $candidate['id'] === (string) $row['external_variant_id']) {
                    $sku = $candidate;
                    break;
                }
            }
            $price = $sku['activity_price']['amount'] ?? $sku['activity_price_amount'] ?? null;
            if ($price === null && ($promo['product_level'] ?? '') === 'PRODUCT') {
                $price = $promo['product']['activity_price']['amount'] ?? $promo['product']['activity_price_amount'] ?? null;
            }
            $ongoing = ($promo['status'] ?? '') === 'ONGOING';
            if ($price === null || ! is_numeric($price) || (float) $price <= 0) {
                if ($ongoing) {
                    $unknown = true;
                }

                continue;
            }
            if ($ongoing) {
                $active[] = (float) $price;
            } elseif (($promo['status'] ?? '') === 'NOT_START') {
                $scheduled[] = (float) $price;
            }
        }
        if ($active) {
            return 'sedang promo Rp '.number_format(min($active), 0, ',', '.');
        }
        if ($unknown) {
            return 'Sedang promo · harga belum tersedia';
        }
        if ($scheduled) {
            return 'Promo terjadwal Rp '.number_format(min($scheduled), 0, ',', '.');
        }

        return '';
    }

    public function save(string $store, string $product, array $input): array
    {
        $c = $this->context($store);
        $rows = $this->rows($store, $product);
        $mode = $input['mode'] ?? '';
        if (! in_array($mode, ['base', 'discount'], true)) {
            throw new InvalidArgumentException('Tab harga tidak valid.');
        }
        $key = (string) ($input['operation_key'] ?? '');
        if (! preg_match('/^[A-Za-z0-9-]{16,64}$/', $key)) {
            throw new InvalidArgumentException('ID operasi tidak valid.');
        }
        $changes = $input['changes'] ?? [];
        if (! is_array($changes) || ! $changes || count($changes) > 300) {
            throw new InvalidArgumentException('Pilih setidaknya satu varian dengan harga baru.');
        }
        $allowed = array_column($rows, 'external_variant_id');
        $prices = [];
        foreach ($changes as $change) {
            $id = (string) ($change['variant_id'] ?? '');
            if (! in_array($id, $allowed, true) || isset($prices[$id])) {
                throw new DomainException('Varian tidak cocok dengan produk/toko.');
            }$prices[$id] = $this->money((string) ($change['price'] ?? ''));
        }
        $lock = 'jl-price-'.substr(hash('sha256', $store.':'.$product), 0, 50);
        $stmt = $this->pdo()->prepare('SELECT GET_LOCK(?,0)');
        $stmt->execute([$lock]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new DomainException('Produk sedang diperbarui. Tunggu lalu coba lagi.');
        }
        $actionId = null;
        $writeStarted = false;
        try {
            $hash = hash('sha256', json_encode([$product, $input], JSON_THROW_ON_ERROR));
            $stmt = $this->pdo()->prepare('SELECT * FROM marketplace_price_actions WHERE store_id=? AND operation_key=?');
            $stmt->execute([$store, $key]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ($existing['request_hash'] !== $hash) {
                    throw new DomainException('ID operasi sudah digunakan untuk perubahan berbeda.');
                }

                return json_decode($existing['result_json'] ?: '{}', true) + ['status' => $existing['status'], 'activity_id' => $existing['remote_activity_id'], 'error_code' => $existing['error_code']];
            }
            $remote = $this->remoteProduct($c, $product);
            $remoteSkus = [];
            foreach ($remote['skus'] as $sku) {
                $remoteSkus[(string) $sku['id']] = $sku;
            }
            foreach ($prices as $id => $price) {
                if (! isset($remoteSkus[$id])) {
                    throw new DomainException('Varian sudah tidak tersedia. Muat ulang katalog.');
                }
            }
            try {
                $promos = $this->promotions($c, $product);
            } catch (Throwable $e) {
                if ($mode === 'discount') {
                    throw $e;
                } $promos = [];
            } $conflicts = [];
            foreach ($promos as $promo) {
                $affected = $promo['product_level'] === 'PRODUCT' ? array_keys($prices) : array_values(array_intersect(array_keys($prices), array_map(static fn ($s) => (string) $s['id'], $promo['product']['skus'] ?? [])));
                if ($affected) {
                    $promo['selected_skus'] = $affected;
                    $conflicts[] = $promo;
                }
            }
            if ($mode === 'base' && $conflicts) {
                throw new DomainException('Harga dasar dikunci promo aktif/terjadwal. Atur promo melalui tab Diskon atau Seller Center terlebih dahulu.');
            }
            if ($mode === 'base' && ($remote['status'] ?? '') !== 'ACTIVATE') {
                throw new DomainException('Harga dasar hanya dapat diubah untuk produk ACTIVATE.');
            }
            if ($mode === 'discount') {
                $title = trim((string) ($input['title'] ?? ''));
                if ($title === '' || mb_strlen($title) > 100) {
                    throw new InvalidArgumentException('Nama promo wajib diisi, maksimal 100 karakter.');
                }
                $begin = (int) ($input['begin_time'] ?? 0);
                $end = (int) ($input['end_time'] ?? 0);
                if ($begin <= time() || $end - $begin < 600) {
                    throw new InvalidArgumentException('Mulai promo harus di masa depan dan durasi minimal 10 menit.');
                }
                foreach ($prices as $id => $price) {
                    $base = $this->basePrice($remoteSkus[$id]);
                    if ($base === null || (float) $price >= (float) $base) {
                        throw new InvalidArgumentException('Harga promo harus lebih rendah dari harga dasar sumber.');
                    }
                }
                if ($conflicts && ($input['replace_existing'] ?? false) !== true) {
                    throw new DomainException('Centang konfirmasi penggantian promo yang sudah ada.');
                }
                foreach ($conflicts as $promo) {
                    if ($promo['product_level'] === 'PRODUCT' && count($prices) !== count($remoteSkus)) {
                        throw new DomainException('Promo lama berlaku untuk seluruh produk. Pilih semua varian atau kelola promo lama di Seller Center.');
                    }
                }
            }
            $actor = (string) ($this->scope->userId());
            $stmt = $this->pdo()->prepare("INSERT INTO marketplace_price_actions(store_id,product_id,operation_key,mode,request_hash,payload_json,status,changed_by) VALUES (?,?,?,?,?,?,'sending',?)");
            $stmt->execute([$store, $product, $key, $mode, $hash, json_encode($input, JSON_THROW_ON_ERROR), $actor]);
            $actionId = (int) $this->pdo()->lastInsertId();
            if ($mode === 'base') {
                $skus = [];
                foreach ($prices as $id => $price) {
                    $skus[] = ['id' => (string) $id, 'price' => ['amount' => $price, 'currency' => 'IDR']];
                }
                $writeStarted = true;
                $this->api($c, 'POST', '/product/202309/products/'.rawurlencode($product).'/prices/update', ['skus' => $skus]);
                $this->pdo()->prepare("UPDATE marketplace_price_actions SET status='remote_applied' WHERE id=?")->execute([$actionId]);
                $updated = $this->remoteProduct($c, $product);
                $verified = [];
                foreach ($updated['skus'] as $sku) {
                    $verified[(string) $sku['id']] = $this->basePrice($sku);
                }
                $result = [];
                foreach ($prices as $id => $price) {
                    $ok = isset($verified[$id]) && $verified[$id] === $price;
                    $result[] = ['variant_id' => (string) $id, 'status' => $ok ? 'verified' : 'pending_verification', 'price' => $verified[$id] ?? null];
                    if ($ok) {
                        $this->pdo()->prepare('UPDATE marketplace_catalog_variants SET base_price=?,price=?,source_updated_at=NOW() WHERE store_id=? AND external_product_id=? AND external_variant_id=?')->execute([$price, $price, $store, $product, $id]);
                    }
                }
            } else {
                $writeStarted = true;
                $activity = $this->api($c, 'POST', '/promotion/202309/activities', ['title' => $title, 'activity_type' => 'FIXED_PRICE', 'product_level' => 'VARIATION', 'duration_type' => 'NORMAL', 'begin_time' => $begin, 'end_time' => $end]);
                $activityId = (string) ($activity['activity_id'] ?? '');
                if ($activityId === '') {
                    throw new MarketplaceIntegrationException('ID promo belum diterima. Periksa Seller Center sebelum mengulang.', 'PROMO_CREATE_UNCERTAIN');
                }
                $this->pdo()->prepare("UPDATE marketplace_price_actions SET remote_activity_id=?,status='created' WHERE id=?")->execute([$activityId, $actionId]);
                foreach ($conflicts as $promo) {
                    $remove = $promo['product_level'] === 'PRODUCT' ? ['product_ids' => [$product]] : ['sku_ids' => array_map('strval', $promo['selected_skus'])];
                    $this->api($c, 'DELETE', '/promotion/202309/activities/'.rawurlencode($promo['id']).'/products', $remove);
                }
                $skus = [];
                foreach ($prices as $id => $price) {
                    $skus[] = ['id' => (string) $id, 'activity_price_amount' => $price, 'quantity_limit' => -1, 'quantity_per_user' => -1];
                }
                $this->api($c, 'PUT', '/promotion/202309/activities/'.rawurlencode($activityId).'/products', ['activity_id' => $activityId, 'products' => [['id' => $product, 'skus' => $skus, 'quantity_limit' => -1, 'quantity_per_user' => -1]]]);
                $this->pdo()->prepare("UPDATE marketplace_price_actions SET status='remote_applied' WHERE id=?")->execute([$actionId]);
                $confirmed = $this->api($c, 'GET', '/promotion/202309/activities/'.rawurlencode($activityId));
                $verified = [];
                foreach (($confirmed['products'] ?? []) as $p) {
                    if ((string) ($p['id'] ?? '') === $product) {
                        foreach (($p['skus'] ?? []) as $sku) {
                            $verified[(string) $sku['id']] = (string) ($sku['activity_price']['amount'] ?? $sku['activity_price_amount'] ?? '');
                        }
                    }
                }
                $result = [];
                foreach ($prices as $id => $price) {
                    $ok = isset($verified[$id]) && is_numeric($verified[$id]) && abs((float) $verified[$id] - (float) $price) < 0.005;
                    $result[] = ['variant_id' => (string) $id, 'status' => $ok ? 'scheduled' : 'pending_verification', 'price' => $price, 'activity_id' => $activityId];
                }
            }
            $status = count(array_filter($result, static fn ($r) => $r['status'] === 'pending_verification')) ? 'pending_verification' : 'verified';
            $output = ['status' => $status, 'rows' => $result, 'activity_id' => $activityId ?? null, 'message' => $mode === 'base' ? 'Harga dikirim ke marketplace; lihat status setiap varian.' : 'Promo dikirim ke marketplace dan berlaku sesuai periode; harga dasar dipertahankan.'];
            $this->pdo()->prepare('UPDATE marketplace_price_actions SET status=?,result_json=? WHERE id=?')->execute([$status, json_encode($output, JSON_THROW_ON_ERROR), $actionId]);

            return $output;
        } catch (Throwable $e) {
            if ($actionId) {
                $code = $e instanceof MarketplaceIntegrationException ? $e->errorCode : 'PRICE_OPERATION_FAILED';
                $rejected = $e instanceof MarketplaceIntegrationException && (in_array($code, ['HTTP_400', 'HTTP_401', 'HTTP_403', 'HTTP_404', 'HTTP_429'], true) || str_starts_with($code, 'TIKTOK_API_'));
                $status = ($writeStarted && ! $rejected) || ! empty($activityId) ? 'needs_review' : 'failed';
                $this->pdo()->prepare('UPDATE marketplace_price_actions SET status=?,error_code=? WHERE id=?')->execute([$status, $code, $actionId]);

                return ['status' => $status, 'error_code' => $code, 'activity_id' => $activityId ?? null, 'message' => $rejected ? ('Marketplace menolak perubahan. '.$e->getMessage().' Periksa data promo dan kode penolakan; otorisasi ulang hanya diperlukan untuk masalah izin/token.') : 'Perubahan belum terkonfirmasi sepenuhnya. Periksa Seller Center sebelum mengirim ulang.'];
            }
            throw $e;
        } finally {
            $this->pdo()->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
    }

    private function pdo(): PDO
    {
        return $this->database->connection()->getPdo();
    }
}
