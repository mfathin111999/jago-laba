<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarketplaceOrderRequest;
use App\Http\Responses\CsvDownload;
use App\Policies\RolePolicy;
use App\Services\Marketplace\LiveGateway;
use App\Services\Marketplace\OrderReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

final class MarketplaceOrderController
{
    public function __construct(private readonly OrderReadService $orders, private readonly LiveGateway $live, private readonly RolePolicy $policy) {}

    public function index(MarketplaceOrderRequest $request): Response
    {
        $companyKey = $request->companyKey();

        try {
            $payload = $this->orders->overview($companyKey, $request->storeId(), $request->filters());

            return Inertia::render('Pilot/MarketplaceOrders', $payload + ['provider' => [
                'live' => $this->live->enabled(),
                'can_fulfill' => $this->policy->allows($request->user('web'), 'shipping.fulfill'),
                'seller_center' => (($payload['active_store']['platform'] ?? '') === 'Shopee') ? 'https://seller.shopee.co.id/' : 'https://seller-id.tokopedia.com/',
            ]]);
        } catch (Throwable $exception) {
            if ($exception instanceof \DomainException && $exception->getCode() === OrderReadService::NO_INTEGRATED_STORE) {
                return Inertia::render('Pilot/MarketplaceOrders', [
                    'read_only' => true, 'workspace' => 'marketplace',
                    'scope' => ['company_key' => $companyKey, 'store_id' => null],
                    'stores' => $this->orders->stores($companyKey), 'active_store' => null,
                    'filters' => $request->filters(), 'orders' => null,
                    'query' => ['tab' => 'pesanan', 'read_only' => true],
                    'message' => 'Belum ada store Marketplace yang terhubung.',
                ]);
            }
            if ($exception instanceof \DomainException && $exception->getCode() === OrderReadService::STORE_SELECTION_REQUIRED) {
                return Inertia::render('Pilot/MarketplaceOrders', [
                    'read_only' => true, 'workspace' => 'marketplace',
                    'scope' => ['company_key' => $companyKey, 'store_id' => null],
                    'stores' => $this->orders->stores($companyKey), 'active_store' => null,
                    'filters' => $request->filters(), 'orders' => null,
                    'query' => ['tab' => 'pesanan', 'read_only' => true], 'message' => $exception->getMessage(),
                ]);
            }
            if ($exception instanceof \DomainException && $exception->getCode() === OrderReadService::STORE_FORBIDDEN) {
                abort(403, $exception->getMessage());
            }
            throw $exception;
        }
    }

    public function detail(Request $request, string $order): JsonResponse
    {
        $companyKey = $request->companyKey();
        $storeId = trim((string) $request->query('store_id', $request->query('store', '')));
        if ($storeId === '') {
            return response()->json(['success' => false, 'message' => 'Toko aktif wajib dipilih.'], 422);
        }

        try {
            $data = $this->orders->detail($companyKey, $storeId, $order);
            if ($data === null) {
                return response()->json(['success' => false, 'message' => 'Pesanan tidak ditemukan.'], 404);
            }

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\DomainException $exception) {
            if ($exception->getCode() === OrderReadService::STORE_FORBIDDEN) {
                return response()->json(['success' => false, 'message' => $exception->getMessage()], 403);
            }
            throw $exception;
        }
    }

    public function export(MarketplaceOrderRequest $request): SymfonyResponse
    {
        try {
            $payload = $this->orders->overview(
                $request->companyKey(),
                $request->storeId(),
                $request->filters() + ['export' => true],
            );
        } catch (\InvalidArgumentException $exception) {
            return response($exception->getMessage(), 422);
        } catch (\DomainException $exception) {
            return match ($exception->getCode()) {
                OrderReadService::NO_INTEGRATED_STORE, OrderReadService::STORE_SELECTION_REQUIRED => response($exception->getMessage(), 422),
                OrderReadService::STORE_FORBIDDEN => response($exception->getMessage(), 403),
                default => throw $exception,
            };
        }

        $storeName = (string) ($payload['active_store']['store_name'] ?? '');
        $rows = array_map(static fn (array $order): array => [
            $storeName, $order['date'], "'".$order['id'], $order['amount'], $order['quantity'],
            $order['courier'], "'".$order['tracking'], $order['status'],
        ], $payload['orders']['rows']);

        return CsvDownload::stream(
            'pesanan-toko.csv',
            ['Toko', 'Tanggal', 'Kode Pesanan', 'Nilai Pesanan', 'Unit', 'Kurir', 'Resi', 'Status'],
            $rows,
        );
    }
}
