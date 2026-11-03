<?php

namespace App\Http\Controllers;

use App\Pilots\Marketplace\MarketplaceFulfillmentWriteService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class MarketplaceFulfillmentController
{
    public function __construct(private readonly MarketplaceFulfillmentWriteService $service) {}

    public function handle(Request $request): JsonResponse
    {
        $action = trim((string) ($request->input('action') ?: 'options'));
        $storeId = (string) ($request->input('store_id') ?? '');
        $orderId = (string) ($request->input('order_id') ?? '');
        $packageId = $request->input('package_id') ? (string) $request->input('package_id') : null;

        if ($storeId === '' || $orderId === '') {
            return response()->json(['success' => false, 'message' => 'Identitas pesanan atau toko tidak valid.'], 422);
        }

        try {
            if ($action === 'options') {
                $packages = $this->service->options(
                    $request->user('web'),
                    (string) $request->attributes->get('company_key'),
                    $storeId,
                    $orderId,
                    $packageId,
                );

                return response()->json(['success' => true, 'data' => ['packages' => $packages]]);
            }

            if ($action === 'confirm') {
                if (($request->input('confirmed') ?? '') !== '1') {
                    throw new DomainException('Konfirmasi pengaturan pengiriman diperlukan.');
                }
                $token = (string) ($request->input('offer_token') ?? '');
                $method = (string) ($request->input('method') ?? '');
                $slot = $request->input('slot_start') !== null ? (int) $request->input('slot_start') : null;

                $res = $this->service->confirm(
                    $request->user('web'),
                    (string) $request->attributes->get('company_key'),
                    $storeId,
                    $orderId,
                    $packageId ?: ('PKG-'.$orderId),
                    $token,
                    $method,
                    $slot,
                );

                return response()->json(['success' => true, 'data' => $res]);
            }

            throw new InvalidArgumentException('Aksi tidak valid.');
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
