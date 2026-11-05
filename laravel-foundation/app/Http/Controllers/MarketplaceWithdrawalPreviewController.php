<?php

namespace App\Http\Controllers;

use App\Auth\LegacyUser;
use App\Http\Requests\MarketplaceWithdrawalPreviewRequest;
use App\Services\Marketplace\MarketplaceWithdrawalPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

final class MarketplaceWithdrawalPreviewController
{
    public function __construct(private readonly MarketplaceWithdrawalPreviewService $preview) {}

    public function __invoke(MarketplaceWithdrawalPreviewRequest $request): JsonResponse
    {
        $user = $request->user('web');
        if (! $user instanceof LegacyUser) {
            return $this->error('Autentikasi diperlukan.', 'AUTH_REQUIRED', 401);
        }

        try {
            $companyKey = $request->companyKey();
            $result = $this->preview->preview(
                $user,
                $companyKey,
                $request->storeId(),
                $request->file('report'),
            );

            $request->session()->put('marketplace.withdrawal_preview', [
                'company_key' => $companyKey,
                'actor' => $this->preview->actorKey($user),
                'store_id' => $request->storeId(),
                'rows' => $result['staged_rows'],
                'hash' => $result['hash'],
                'key' => $result['key'],
                'expires_at' => $result['expires_at'],
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'key' => $result['key'],
                    'count' => $result['count'],
                    'rows' => $result['rows'],
                    'meta' => $result['meta'],
                ],
                'read_only' => true,
                'provider_call' => false,
                'write' => false,
                'preview_expires_at' => gmdate(DATE_ATOM, $result['expires_at']),
            ])->header('Cache-Control', 'no-store, private');
        } catch (\DomainException $exception) {
            return $this->error($exception->getMessage(), 'STORE_FORBIDDEN', 403);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 'IMPORT_PREVIEW_INVALID', 422);
        } catch (Throwable $exception) {
            Log::warning('Marketplace withdrawal preview failed.', [
                'exception' => $exception::class,
            ]);

            return $this->error('Impor belum dapat dipratinjau. Tidak ada perubahan yang dikonfirmasi.', 'IMPORT_PREVIEW_FAILED', 422);
        }
    }

    private function error(string $message, string $code, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'code' => $code,
        ], $status)->header('Cache-Control', 'no-store, private');
    }
}
