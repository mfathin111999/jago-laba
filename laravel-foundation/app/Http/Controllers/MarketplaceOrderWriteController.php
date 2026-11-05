<?php

namespace App\Http\Controllers;

use App\Pilots\Marketplace\MarketplaceOrderWriteService;
use App\Support\RequestId;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;

final class MarketplaceOrderWriteController
{
    public function __construct(private readonly MarketplaceOrderWriteService $service) {}

    public function addManual(Request $request): JsonResponse
    {
        $requestId = RequestId::current();

        try {
            $orderId = $this->service->recordManualOrder(
                $request->user('web'),
                (string) $request->attributes->get('company_key'),
                $request->all(),
            );

            return response()->json([
                'success' => true,
                'order_id' => $orderId,
                'message' => "Pesanan manual {$orderId} berhasil ditambahkan.",
            ])->header('X-Request-Id', $requestId);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403)->header('X-Request-Id', $requestId);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422)->header('X-Request-Id', $requestId);
        }
    }

    public function updateManual(Request $request): JsonResponse
    {
        $requestId = RequestId::current();
        $orderId = trim((string) ($request->input('order_id') ?? $request->route('order') ?? ''));
        $storeId = trim((string) ($request->input('store_id') ?? $request->query('store_id') ?? ''));

        try {
            $res = $this->service->updateManualOrder(
                $request->user('web'),
                (string) $request->attributes->get('company_key'),
                $orderId,
                $storeId,
                $request->all(),
            );

            return response()->json($res)->header('X-Request-Id', $requestId);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403)->header('X-Request-Id', $requestId);
        } catch (LogicException|InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422)->header('X-Request-Id', $requestId);
        }
    }

    public function archive(Request $request): JsonResponse
    {
        $requestId = RequestId::current();
        $orderId = trim((string) ($request->input('order_id') ?? $request->route('order') ?? ''));
        $storeId = trim((string) ($request->input('store_id') ?? $request->query('store_id') ?? ''));
        $reason = trim((string) ($request->input('reason') ?? ''));

        try {
            $res = $this->service->archiveOrder(
                $request->user('web'),
                (string) $request->attributes->get('company_key'),
                $orderId,
                $storeId,
                $reason,
            );

            return response()->json($res)->header('X-Request-Id', $requestId);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403)->header('X-Request-Id', $requestId);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422)->header('X-Request-Id', $requestId);
        }
    }

    public function getJournal(Request $request): JsonResponse
    {
        $storeId = trim((string) ($request->input('store_id') ?? $request->query('store_id') ?? ''));
        $res = $this->service->getJournalEntries(
            (string) $request->attributes->get('company_key'),
            $storeId,
            $request->query('date_from'),
            $request->query('date_to'),
            $request->query('ref_type'),
        );

        return response()->json(['success' => true, 'data' => $res]);
    }
}
