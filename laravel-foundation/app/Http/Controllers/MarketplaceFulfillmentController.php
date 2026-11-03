<?php

namespace App\Http\Controllers;

use App\Exceptions\ProviderConnectorInactive;
use App\Pilots\Marketplace\MarketplaceFulfillmentWriteService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Legacy-compatible POST /api/marketplace-fulfillment.php (actions: options, confirm).
 * The provider connector is disabled, so valid requests are answered 503 and change nothing.
 */
final class MarketplaceFulfillmentController
{
    public function __construct(private readonly MarketplaceFulfillmentWriteService $service) {}

    public function handle(Request $request): JsonResponse
    {
        $action = trim((string) ($request->input('action') ?: 'options'));
        $storeId = (string) ($request->input('store_id') ?? '');
        $orderId = (string) ($request->input('order_id') ?? '');

        if ($storeId === '' || $orderId === '') {
            return response()->json(['success' => false, 'message' => 'Identitas pesanan atau toko tidak valid.'], 422);
        }

        try {
            $companyKey = (string) $request->attributes->get('company_key');

            if ($action === 'options') {
                $this->service->options($companyKey, $storeId, $orderId);
            }
            if ($action === 'confirm') {
                if (($request->input('confirmed') ?? '') !== '1') {
                    throw new DomainException('Konfirmasi pengaturan pengiriman diperlukan.');
                }
                $this->service->confirm($companyKey, $storeId, $orderId);
            }

            throw new InvalidArgumentException('Aksi tidak valid.');
        } catch (ProviderConnectorInactive $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'error_code' => ProviderConnectorInactive::CODE], 503);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
