<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarketplaceExpenseDeleteRequest;
use App\Http\Responses\ApiResponse;
use App\Pilots\Marketplace\MarketplaceExpenseWriteService;
use App\Support\RequestId;
use Illuminate\Http\JsonResponse;

final class MarketplaceExpenseDeleteController
{
    public function __construct(private readonly MarketplaceExpenseWriteService $expenses) {}

    public function __invoke(MarketplaceExpenseDeleteRequest $request): JsonResponse
    {
        // StoreForbidden and ResourceNotFound are rendered by the exception handler.
        $result = ApiResponse::guard(
            fn (): array => $this->expenses->delete(
                $request->user('web'),
                (string) $request->attributes->get('company_key'),
                (string) $request->validated('store_id'),
                (int) $request->validated('id'),
            ),
            'DELETE_FAILED',
            'Pengeluaran belum berhasil dihapus.',
            'Marketplace expense delete failed.',
        );
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json($result)->header('X-Request-Id', RequestId::current());
    }
}
