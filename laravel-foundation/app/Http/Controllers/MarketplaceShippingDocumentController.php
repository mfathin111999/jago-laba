<?php

namespace App\Http\Controllers;

use App\Authorization\StoreContext;
use App\Pilots\Marketplace\OrderReadService;
use App\Security\CsrfTokenValidator;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Legacy-compatible /api/marketplace-shipping-document.php.
 *
 * POST prepare would fetch the shipping label PDF from the marketplace, which needs a live provider call and
 * stays disabled: the request is validated like the legacy one, then answered as unavailable. GET serves a
 * cached PDF; Laravel never creates one yet, so a request for any id answers that it expired.
 */
final class MarketplaceShippingDocumentController
{
    public function __construct(
        private readonly OrderReadService $orders,
        private readonly StoreContext $stores,
        private readonly CsrfTokenValidator $csrf,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            if ($request->isMethod('POST')) {
                return $this->prepare($request);
            }
            if (! $request->isMethod('GET')) {
                throw new InvalidArgumentException('Metode tidak didukung.');
            }
            if (preg_match('/^[a-f0-9]{48}$/', (string) $request->query('id', '')) !== 1) {
                throw new DomainException('Dokumen tidak tersedia.');
            }

            throw new DomainException('Dokumen kedaluwarsa atau tidak tersedia. Klik Cetak Resi untuk mengambil ulang.');
        } catch (DomainException $exception) {
            return $this->json(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (InvalidArgumentException $exception) {
            return $this->json(['success' => false, 'message' => $exception->getMessage()], 400);
        } catch (Throwable $exception) {
            Log::error('Marketplace shipping document failed.', ['exception' => $exception::class, 'message' => $exception->getMessage()]);

            return $this->json(['success' => false, 'message' => 'Dokumen belum dapat diambil. Periksa sesi login, koneksi toko, dan izin Fulfillment.'], 400);
        }
    }

    private function prepare(Request $request): JsonResponse
    {
        if (! $this->csrf->valid($request)) {
            return $this->json(['success' => false, 'message' => 'Token CSRF tidak valid.', 'code' => 'CSRF_INVALID'], 419);
        }

        $storeId = trim((string) $request->input('store_id', ''));
        $orderId = (string) $request->input('order_id', '');
        if (strlen($storeId) > 120 || preg_match('/^[a-zA-Z0-9_-]{1,120}$/', $orderId) !== 1) {
            throw new InvalidArgumentException('Identitas pesanan tidak valid.');
        }
        if ($storeId === '' || ! $this->stores->canAccess($request->user('web'), $storeId)) {
            throw new DomainException('Store tidak diizinkan.');
        }

        $detail = $this->orders->detail((string) $request->attributes->get('company_key'), $storeId, $orderId);
        $order = $detail['order'] ?? null;
        if ($order === null || ! in_array($order['internal_state'] ?? null, [null, 'active'], true)) {
            throw new DomainException('Pesanan tidak ditemukan di toko aktif.');
        }
        if (($order['platform'] ?? '') !== 'TikTok Shop') {
            throw new DomainException('Konektor PDF resi Shopee belum tersedia. Gunakan Seller Center Shopee.');
        }
        if (in_array($order['source_order_status'] ?? $order['order_status'] ?? '', ['UNPAID', 'CANCELLED', 'Dibatalkan'], true)) {
            throw new DomainException('Pesanan belum dibayar atau sudah dibatalkan. Resi tidak diminta.');
        }

        return $this->json([
            'success' => false,
            'message' => 'Konektor resi marketplace belum aktif. Ambil resi melalui Seller Center.',
            'error_code' => 'PROVIDER_CONNECTOR_INACTIVE',
        ], 503);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'no-store, private')->header('X-Content-Type-Options', 'nosniff');
    }
}
