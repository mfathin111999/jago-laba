<?php

namespace App\Services\Marketplace\Shipping;

use App\Exceptions\MarketplaceIntegrationException;
use App\Services\Marketplace\Concerns\RunsSql;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class FulfillmentService extends ShippingDocumentService
{
    use RunsSql;

    private function writable(): void
    {
        if (! preg_match('/OWNER|ADMIN/i', (string) ($this->scope->role()))) {
            throw new DomainException('Pengaturan pengiriman hanya untuk Owner atau Admin.');
        }
    }

    private function order(array $connection, string $store, string $order): array
    {
        if (! $this->value("SELECT id FROM marketplace_orders WHERE store_id=? AND id=? AND source_kind='api' AND (internal_state IS NULL OR internal_state='active')", [$store, $order])) {
            throw new DomainException('Pesanan tidak ditemukan di toko aktif.');
        }
        $data = $this->call($connection, '/order/202309/orders', ['ids' => $order]);
        foreach ($data['orders'] ?? [] as $raw) {
            if ((string) ($raw['id'] ?? '') === $order) {
                if (in_array($raw['status'] ?? '', ['UNPAID', 'CANCELLED'], true)) {
                    throw new DomainException('Pesanan belum dibayar atau sudah dibatalkan.');
                }
                if (! empty($raw['is_buyer_request_cancel'])) {
                    throw new DomainException('Pesanan memiliki pengajuan pembatalan. Periksa pengajuan sebelum mengatur pengiriman.');
                }

                return $raw;
            }
        }
        throw new DomainException('Pesanan terbaru belum tersedia dari API.');
    }

    private function action(string $store, string $package): ?array
    {

        return $this->row('SELECT * FROM marketplace_fulfillment_actions WHERE company_key=? AND store_id=? AND package_id=?', [$this->scope->companyKey(), $store, $package]) ?: null;
    }

    private function state(string $store, string $package, string $state, ?string $error = null): void
    {
        $this->run('UPDATE marketplace_fulfillment_actions SET state=?,last_error=?,updated_at=NOW() WHERE company_key=? AND store_id=? AND package_id=?', [$state, $error, $this->scope->companyKey(), $store, $package]);
    }

    private function detail(array $c, string $package, string $order): array
    {
        $detail = $this->call($c, '/fulfillment/202309/packages/'.rawurlencode($package), []);
        if ((string) ($detail['package_id'] ?? '') !== $package) {
            throw new DomainException('Identitas paket dari API tidak sesuai.');
        }
        $ids = array_map(static fn ($o) => (string) ($o['id'] ?? ''), $detail['orders'] ?? []);
        if (! in_array($order, $ids, true)) {
            throw new DomainException('Paket tidak terhubung dengan pesanan yang dipilih.');
        }
        if (($detail['shipping_type'] ?? '') !== 'TIKTOK') {
            throw new DomainException('Paket ini menggunakan Seller Shipping atau layanan khusus. Pengaturan TikTok Shipping tidak tersedia.');
        }

        return $detail;
    }

    public static function methods(array $slots): array
    {
        $out = [];
        $pickup = [];
        foreach ($slots['pickup_slots'] ?? [] as $slot) {
            $start = $slot['start_time'] ?? null;
            $end = $slot['end_time'] ?? null;
            if (($slot['avaliable'] ?? $slot['available'] ?? false) === true && is_numeric($start) && is_numeric($end) && (int) $start > 0 && (int) $end > time() && (int) $end >= (int) $start) {
                $pickup[] = ['start_time' => (int) $start, 'end_time' => (int) $end];
            }
        }
        if (($slots['can_pickup'] ?? false) === true && $pickup) {
            $out[] = ['id' => 'PICKUP', 'name' => 'Pickup kurir', 'slots' => $pickup];
        }
        if (($slots['can_drop_off'] ?? false) === true) {
            $out[] = ['id' => 'DROP_OFF', 'name' => 'Drop-off ke kurir', 'slots' => []];
        }

        return $out;
    }

    public function options(string $store, string $order, ?string $onlyPackage = null): array
    {
        $this->writable();
        $c = $this->connection($store);
        $raw = $this->order($c, $store, $order);
        $packages = self::packages($raw);
        if (! $packages) {
            throw new DomainException('ID paket belum tersedia dari marketplace. Sinkronkan pesanan lalu coba lagi.');
        }
        if ($onlyPackage !== null && ! in_array($onlyPackage, $packages, true)) {
            throw new DomainException('Paket tidak sesuai dengan pesanan.');
        }
        if (count($packages) > 10) {
            throw new DomainException('Maksimal 10 paket per pesanan.');
        }$result = [];
        foreach ($packages as $package) {
            if ($onlyPackage !== null && $onlyPackage !== $package) {
                continue;
            }
            try {
                $previous = $this->action($store, $package);
                if (! in_array($raw['status'] ?? '', ['AWAITING_SHIPMENT', 'AWAITING_COLLECTION', 'PARTIALLY_SHIPPING', 'IN_TRANSIT', 'DELIVERED', 'COMPLETED'], true)) {
                    throw new DomainException('Status pesanan belum mengizinkan pengaturan pengiriman.');
                }
                if (($raw['status'] ?? '') !== 'AWAITING_SHIPMENT' || ($previous['state'] ?? '') === 'confirmed') {
                    if ($previous && in_array($raw['status'] ?? '', ['AWAITING_COLLECTION', 'IN_TRANSIT', 'DELIVERED', 'COMPLETED'], true)) {
                        $this->state($store, $package, 'confirmed');
                    }
                    $result[] = ['package_id' => $package, 'ready' => true, 'status' => $raw['status'] ?? 'UNKNOWN'];

                    continue;
                }
                if (in_array($previous['state'] ?? '', ['requested', 'uncertain'], true)) {
                    $result[] = ['package_id' => $package, 'ready' => false, 'pending' => true, 'message' => 'Permintaan sebelumnya sedang dipastikan. Klik Periksa Status; aplikasi tidak mengirim pengaturan ulang.'];

                    continue;
                }
                $detail = $this->detail($c, $package, $order);
                $slots = $this->call($c, '/fulfillment/202309/packages/'.rawurlencode($package).'/handover_time_slots', []);
                $methods = self::methods($slots);
                if (! $methods) {
                    throw new DomainException('Tidak ada metode atau jadwal pengiriman yang tersedia dari API saat ini.');
                }
                $token = bin2hex(random_bytes(24));
                $offer = ['methods' => $methods];
                $this->run("INSERT INTO marketplace_fulfillment_actions(company_key,store_id,package_id,order_id,user_key,state,offer_hash,offer_json,offer_expires_at) VALUES(?,?,?,?,?,'offered',?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE)) ON DUPLICATE KEY UPDATE order_id=VALUES(order_id),user_key=VALUES(user_key),state=IF(state IN ('requested','uncertain','confirmed'),state,'offered'),offer_hash=VALUES(offer_hash),offer_json=VALUES(offer_json),offer_expires_at=VALUES(offer_expires_at)", [$this->scope->companyKey(), $store, $package, $order, $this->user(), hash('sha256', $token), json_encode($offer, JSON_THROW_ON_ERROR)]);
                $point = (string) ($slots['drop_off_point_url'] ?? '');
                if (! preg_match('~^https://[^\s]+$~', $point)) {
                    $point = '';
                }
                $result[] = ['package_id' => $package, 'ready' => false, 'offer_token' => $token, 'methods' => $methods, 'carrier' => (string) ($detail['shipping_provider_name'] ?? ''), 'service' => (string) ($detail['delivery_option_name'] ?? ''), 'warehouse_address' => (string) ($detail['sender_address']['full_address'] ?? $detail['sender_address']['address_detail'] ?? ''), 'affected_orders' => array_values(array_filter(array_map(static fn ($o) => (string) ($o['id'] ?? ''), $detail['orders'] ?? []))), 'drop_off_point_url' => $point];
            } catch (Throwable $e) {
                $result[] = ['package_id' => $package, 'ready' => false, 'error' => true, 'message' => self::safeError($e)];
            }
        }

        return $result;
    }

    public function confirm(string $store, string $order, string $package, string $token, string $method, ?int $slotStart = null): array
    {
        $this->writable();
        $c = $this->connection($store);
        $company = $this->scope->companyKey();
        $lock = 'jlship:'.substr(hash('sha256', $company.'|'.$store.'|'.$package), 0, 48);
        if ((int) $this->value('SELECT GET_LOCK(?,0)', [$lock]) !== 1) {
            throw new DomainException('Paket sedang diproses. Periksa status beberapa saat lagi.');
        }
        try {
            $raw = $this->order($c, $store, $order);
            if (! in_array($package, self::packages($raw), true)) {
                throw new DomainException('Paket tidak sesuai pesanan.');
            }
            $action = $this->action($store, $package);
            if (in_array($raw['status'] ?? '', ['AWAITING_COLLECTION', 'IN_TRANSIT', 'DELIVERED', 'COMPLETED'], true) || ($action['state'] ?? '') === 'confirmed') {
                if ($action) {
                    $this->state($store, $package, 'confirmed');
                }

                return ['confirmed' => true, 'already_arranged' => true];
            }
            if (($raw['status'] ?? '') !== 'AWAITING_SHIPMENT') {
                throw new DomainException('Status pesanan tidak mengizinkan pengaturan pengiriman.');
            }
            if (! $action || $action['order_id'] !== $order || $action['user_key'] !== $this->user() || ! hash_equals((string) $action['offer_hash'], hash('sha256', $token)) || strtotime((string) $action['offer_expires_at']) < time()) {
                throw new DomainException('Pilihan pengiriman kedaluwarsa. Muat ulang pilihan dari marketplace.');
            }
            if (in_array($action['state'], ['requested', 'uncertain'], true)) {
                return ['confirmed' => false, 'pending' => true, 'message' => 'Hasil permintaan sebelumnya belum pasti. Periksa Status sebelum tindakan berikutnya.'];
            }
            $detail = $this->detail($c, $package, $order);
            $available = self::methods($this->call($c, '/fulfillment/202309/packages/'.rawurlencode($package).'/handover_time_slots', []));
            $saved = json_decode($action['offer_json'], true)['methods'] ?? [];
            $chosen = null;
            foreach ($available as $option) {
                if ($option['id'] === $method) {
                    $chosen = $option;
                }
            }
            if (! $chosen || ! in_array($method, array_column($saved, 'id'), true)) {
                throw new DomainException('Metode pengiriman tidak tersedia. Muat ulang pilihan.');
            }
            $body = ['handover_method' => $method];
            if ($method === 'PICKUP') {
                $valid = null;
                foreach ($chosen['slots'] as $slot) {
                    if ($slot['start_time'] === $slotStart) {
                        $valid = $slot;
                    }
                }$savedSlot = false;
                foreach ($saved as $o) {
                    foreach ($o['slots'] as $slot) {
                        if ($o['id'] === $method && $slot === $valid) {
                            $savedSlot = true;
                        }
                    }
                }if (! $valid || ! $savedSlot) {
                    throw new DomainException('Jadwal pickup tidak tersedia. Pilih jadwal terbaru.');
                }$body['pickup_slot'] = $valid;
            }
            $this->run("UPDATE marketplace_fulfillment_actions SET state='requested',handover_method=?,requested_at=NOW(),last_error=NULL WHERE id=?", [$method, $action['id']]);
            $this->audit($store, $order, $package, 'shipment_requested');
            try {
                $this->call($c, '/fulfillment/202309/packages/'.rawurlencode($package).'/ship', [], 'POST', $body);
                $this->state($store, $package, 'confirmed');
                $this->audit($store, $order, $package, 'shipment_confirmed');

                return ['confirmed' => true, 'already_arranged' => false];
            } catch (Throwable $e) {
                $code = self::errorCode($e);
                $uncertain = ! str_starts_with($code, 'TIKTOK_API_') || in_array($code, ['TIKTOK_API_36009003', 'TIKTOK_API_21001003', 'TIKTOK_API_21001011', 'TIKTOK_API_11001031'], true);
                $this->state($store, $package, $uncertain ? 'uncertain' : 'failed', $code);
                $this->audit($store, $order, $package, $uncertain ? 'shipment_uncertain' : 'shipment_failed', $code);

                return ['confirmed' => false, 'pending' => $uncertain, 'message' => $uncertain ? 'Hasil pengaturan belum pasti. Periksa Status; permintaan tidak akan dikirim ulang otomatis.' : self::safeError($e)];
            }
        } finally {
            $this->run('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    public static function errorCode(Throwable $e): string
    {
        $code = $e instanceof MarketplaceIntegrationException ? $e->errorCode : 'FULFILLMENT_FAILED';
        if (str_starts_with($code, 'HTTP_') && preg_match('/Code: (\d{1,12})/', $e->getMessage(), $m)) {
            $code = 'TIKTOK_API_'.$m[1];
        }

        return $code;
    }

    public static function safeError(Throwable $e): string
    {
        if ($e instanceof DomainException || $e instanceof InvalidArgumentException) {
            return $e->getMessage();
        }$code = self::errorCode($e);
        if (in_array($code, ['HTTP_401', 'HTTP_403', 'TIKTOK_API_105005'], true)) {
            return 'Izin Fulfillment ditolak. Aktifkan Fulfillment Basic dan Package Split And Combine lalu otorisasi ulang toko.';
        }

        return 'Marketplace belum dapat mengatur pengiriman. Periksa status paket dan coba lagi. Kode: '.$code;
    }
}
