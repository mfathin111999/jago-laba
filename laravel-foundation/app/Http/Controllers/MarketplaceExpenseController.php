<?php

namespace App\Http\Controllers;

use App\Exceptions\IdempotencyConflict;
use App\Http\Requests\MarketplaceExpenseRequest;
use App\Pilots\Marketplace\MarketplaceExpenseWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

final class MarketplaceExpenseController
{
    public function __construct(private readonly MarketplaceExpenseWriteService $expenses) {}

    public function __invoke(MarketplaceExpenseRequest $request): JsonResponse|RedirectResponse
    {
        $requestId = bin2hex(random_bytes(8));
        $idempotencyKey = trim((string) ($request->header('X-Idempotency-Key') ?: $request->input('idempotency_key')));
        if ($idempotencyKey === '') {
            if ($request->routeIs('pilot.marketplace.expenses')) {
                return $this->error('Header X-Idempotency-Key wajib diisi.', 'IDEMPOTENCY_REQUIRED', 422, $requestId);
            }

            $idempotencyKey = 'legacy-'.bin2hex(random_bytes(16));
        }

        try {
            $result = $this->expenses->create(
                $request->user('web'),
                (string) $request->attributes->get('company_key'),
                $request->validated(),
                $idempotencyKey,
            );
        } catch (IdempotencyConflict $exception) {
            return $this->error($exception->getMessage(), 'IDEMPOTENCY_CONFLICT', 409, $requestId);
        } catch (\DomainException $exception) {
            return $this->error($exception->getMessage(), 'STORE_FORBIDDEN', 403, $requestId);
        } catch (Throwable $exception) {
            Log::error('Marketplace expense write failed.', [
                'request_id' => $requestId,
                'exception' => $exception::class,
            ]);

            return $this->error('Pengeluaran belum berhasil disimpan.', 'WRITE_FAILED', 500, $requestId);
        }

        if ($request->routeIs('pilot.marketplace.expenses') && ! $request->expectsJson()) {
            return redirect()->route('pilot.marketplace', [
                'store' => (string) $request->validated('store_id'),
            ], 303)->with('write_result', $result);
        }

        return response()->json($result)->header('X-Request-Id', $requestId);
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
