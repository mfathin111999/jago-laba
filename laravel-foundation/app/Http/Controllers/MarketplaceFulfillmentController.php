<?php

namespace App\Http\Controllers;

use App\Marketplace\LegacyRuntime;
use App\Exceptions\ProviderConnectorInactive;
use App\Marketplace\LiveGateway;
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
    public function __construct(private readonly MarketplaceFulfillmentWriteService $service, private readonly LiveGateway $live) {}

    public function handle(Request $request): JsonResponse
    {
        $action = trim((string) ($request->input('action') ?: 'options'));
        $storeId = (string) ($request->input('store_id') ?? '');
        $orderId = (string) ($request->input('order_id') ?? '');

        if ($storeId === '' || $orderId === '') {
            return response()->json(['success' => false, 'message' => 'Identitas pesanan atau toko tidak valid.'], 422);
        }

        try {
            $companyKey = $request->companyKey();

            if ($this->live->enabled() && in_array($action, ['options', 'confirm'], true)) {
                return $this->live($request, $action, $companyKey, $storeId, $orderId);
            }

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

    /** Live mode: the legacy fulfillment service talks to the marketplace; same request and answer shape as the legacy endpoint. */
    private function live(Request $request, string $action, string $companyKey, string $storeId, string $orderId): JsonResponse
    {
        $package = (string) ($request->input('package_id') ?? '');
        if (strlen($storeId) > 120 || preg_match('/^[a-zA-Z0-9_-]{1,120}$/', $orderId) !== 1 || ($package !== '' && preg_match('/^[a-zA-Z0-9_-]{1,120}$/', $package) !== 1)) {
            return response()->json(['success' => false, 'message' => 'Identitas pesanan atau paket tidak valid.'], 422);
        }
        if ($action === 'confirm' && ((string) $request->input('confirmed')) !== '1') {
            return response()->json(['success' => false, 'message' => 'Konfirmasi pengaturan pengiriman diperlukan.'], 403);
        }
        $slot = $request->input('slot_start');
        $slot = ($slot === null || $slot === '') ? null : filter_var($slot, FILTER_VALIDATE_INT);
        if ($slot === false) {
            return response()->json(['success' => false, 'message' => 'Jadwal tidak valid.'], 422);
        }

        try {
            $data = $this->liveGateway()->run($request->user('web'), $companyKey, ['MarketplaceFulfillmentService.php'], static function (\PDO $pdo) use ($action, $storeId, $orderId, $package, $request, $slot): mixed {
                $service = new \MarketplaceFulfillmentService($pdo);

                return $action === 'options'
                    ? ['packages' => $service->options($storeId, $orderId, $package !== '' ? $package : null)]
                    : $service->confirm($storeId, $orderId, $package, (string) $request->input('offer_token', ''), (string) $request->input('method', ''), $slot);
            });

            return response()->json(['success' => true, 'data' => $data])->header('Cache-Control', 'no-store, private');
        } catch (\Throwable $e) {
            LegacyRuntime::service('MarketplaceFulfillmentService');

            return response()->json(['success' => false, 'message' => \MarketplaceFulfillmentService::safeError($e)], $e instanceof DomainException ? 403 : 400)->header('Cache-Control', 'no-store, private');
        }
    }

    private function liveGateway(): LiveGateway
    {
        return $this->live;
    }
}
