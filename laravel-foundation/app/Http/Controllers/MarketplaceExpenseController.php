<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarketplaceExpenseRequest;
use App\Http\Responses\ApiResponse;
use App\Pilots\Marketplace\MarketplaceExpenseWriteService;
use App\Support\RequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

final class MarketplaceExpenseController
{
    public function __construct(private readonly MarketplaceExpenseWriteService $expenses) {}

    public function __invoke(MarketplaceExpenseRequest $request): JsonResponse|RedirectResponse
    {
        $requestId = RequestId::current();
        $idempotencyKey = trim((string) ($request->header('X-Idempotency-Key') ?: $request->input('idempotency_key')));
        if ($idempotencyKey === '') {
            if ($request->routeIs('pilot.marketplace.expenses')) {
                return ApiResponse::error('Header X-Idempotency-Key wajib diisi.', 'IDEMPOTENCY_REQUIRED', 422);
            }

            $idempotencyKey = 'legacy-'.bin2hex(random_bytes(16));
        }

        // StoreForbidden and IdempotencyConflict are rendered by the exception handler.
        $result = ApiResponse::guard(
            fn (): array => $this->expenses->create(
                $request->user('web'),
                $request->companyKey(),
                $request->validated(),
                $idempotencyKey,
            ),
            'WRITE_FAILED',
            'Pengeluaran belum berhasil disimpan.',
            'Marketplace expense write failed.',
        );
        if ($result instanceof JsonResponse) {
            return $result;
        }

        if ($request->routeIs('pilot.marketplace.expenses') && ! $request->expectsJson()) {
            return redirect()->route('pilot.marketplace', [
                'store' => (string) $request->validated('store_id'),
            ], 303)->with('write_result', $result);
        }

        return response()->json($result)->header('X-Request-Id', $requestId);
    }
}
