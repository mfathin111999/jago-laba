<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarketplaceSyncRequest;
use App\Http\Responses\ApiResponse;
use App\Pilots\Marketplace\MarketplaceSyncDispatchService;
use App\Support\RequestId;
use DomainException;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

final class MarketplaceSyncController
{
    public function __construct(private readonly MarketplaceSyncDispatchService $dispatcher) {}

    public function __invoke(MarketplaceSyncRequest $request): JsonResponse
    {
        // AssignRequestId already kept a valid client X-Request-Id, so this is the id the sync run is recorded under.
        $requestId = RequestId::current();

        $result = ApiResponse::guard(
            fn (): array => $this->dispatcher->dispatchForStore(
                $request->companyKey(),
                (string) $request->validated('store_id'),
                'browser',
                (string) ($request->validated('run_key') ?: $request->header('X-Idempotency-Key') ?: ''),
                $requestId,
            ),
            'SYNC_QUEUE_UNAVAILABLE',
            'Sinkronisasi belum dapat diantrikan.',
            'Marketplace queue dispatch failed.',
            refusals: [DomainException::class => [403, 'SYNC_SCOPE_FORBIDDEN'], InvalidArgumentException::class => [422, 'SYNC_INPUT_INVALID']],
            failureStatus: 503,
        );
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ], $result['duplicate'] ? 200 : 202)->header('X-Request-Id', $requestId);
    }
}
