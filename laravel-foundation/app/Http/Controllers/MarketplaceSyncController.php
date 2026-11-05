<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarketplaceSyncRequest;
use App\Pilots\Marketplace\MarketplaceSyncDispatchService;
use App\Support\RequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

final class MarketplaceSyncController
{
    public function __construct(private readonly MarketplaceSyncDispatchService $dispatcher) {}

    public function __invoke(MarketplaceSyncRequest $request): JsonResponse
    {
        $requestId = $this->requestId($request->header('X-Request-Id'));

        try {
            $result = $this->dispatcher->dispatchForStore(
                (string) $request->attributes->get('company_key'),
                (string) $request->validated('store_id'),
                'browser',
                (string) ($request->validated('run_key') ?: $request->header('X-Idempotency-Key') ?: ''),
                $requestId,
            );
        } catch (\DomainException $exception) {
            return $this->error($exception->getMessage(), 'SYNC_SCOPE_FORBIDDEN', 403, $requestId);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 'SYNC_INPUT_INVALID', 422, $requestId);
        } catch (Throwable $exception) {
            Log::error('Marketplace queue dispatch failed.', [
                'request_id' => $requestId,
                'exception' => $exception::class,
            ]);

            return $this->error('Sinkronisasi belum dapat diantrikan.', 'SYNC_QUEUE_UNAVAILABLE', 503, $requestId);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ], $result['duplicate'] ? 200 : 202)->header('X-Request-Id', $requestId);
    }

    private function requestId(?string $value): string
    {
        $value = trim((string) $value);

        return RequestId::isValid($value) ? $value : RequestId::current();
    }

    private function error(string $message, string $code, int $status, string $requestId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'code' => $code,
            'request_id' => $requestId,
        ], $status)->header('X-Request-Id', $requestId);
    }
}
