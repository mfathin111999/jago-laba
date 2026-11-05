<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarketplaceExpenseDeleteRequest;
use App\Pilots\Marketplace\MarketplaceExpenseWriteService;
use App\Support\RequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

final class MarketplaceExpenseDeleteController
{
    public function __construct(private readonly MarketplaceExpenseWriteService $expenses) {}

    public function __invoke(MarketplaceExpenseDeleteRequest $request): JsonResponse
    {
        $requestId = RequestId::current();

        try {
            $result = $this->expenses->delete(
                $request->user('web'),
                (string) $request->attributes->get('company_key'),
                (string) $request->validated('store_id'),
                (int) $request->validated('id'),
            );
        } catch (\DomainException $exception) {
            $status = $exception->getCode() === 404 ? 404 : 403;
            $code = $status === 404 ? 'EXPENSE_NOT_FOUND' : 'STORE_FORBIDDEN';

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => $code,
                'request_id' => $requestId,
            ], $status)->header('X-Request-Id', $requestId);
        } catch (Throwable $exception) {
            Log::error('Marketplace expense delete failed.', [
                'request_id' => $requestId,
                'exception' => $exception::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Pengeluaran belum berhasil dihapus.',
                'code' => 'DELETE_FAILED',
                'request_id' => $requestId,
            ], 500)->header('X-Request-Id', $requestId);
        }

        return response()->json($result)->header('X-Request-Id', $requestId);
    }
}
