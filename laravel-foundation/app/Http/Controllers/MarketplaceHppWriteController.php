<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarketplaceGlobalHppUpdateRequest;
use App\Http\Requests\MarketplaceHppBatchRequest;
use App\Http\Requests\MarketplaceSkuHppUpdateRequest;
use App\Http\Responses\ApiResponse;
use App\Pilots\Marketplace\MarketplaceHppService;
use App\Support\RequestId;
use DomainException;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

final class MarketplaceHppWriteController
{
    public function __construct(private readonly MarketplaceHppService $service) {}

    public function updateSku(MarketplaceSkuHppUpdateRequest $request): JsonResponse
    {
        return $this->respond(ApiResponse::guard(
            fn (): array => $this->service->updateSkuHpp(
                $request->user('web'),
                $request->companyKey(),
                (string) $request->validated('store_id'),
                (string) $request->validated('sku'),
                (float) $request->validated('hpp'),
                $request->validated('product_variant_id'),
            ),
            'UPDATE_HPP_FAILED',
            'Gagal memperbarui HPP SKU.',
            'Update SKU HPP failed.',
            logExceptionMessage: true,
            refusals: [DomainException::class => [403, 'STORE_FORBIDDEN'], InvalidArgumentException::class => [422, 'HPP_INPUT_INVALID']],
        ));
    }

    public function updateGlobal(MarketplaceGlobalHppUpdateRequest $request): JsonResponse
    {
        $applyAll = (bool) $request->validated('apply_all_variants');
        $storeId = (string) $request->validated('store_id');
        $amount = (string) $request->validated('hpp');
        $recalculate = (bool) $request->validated('recalculate_history');
        $effective = $request->validated('effective_from');
        $companyKey = $request->companyKey();

        return $this->respond(ApiResponse::guard(
            function () use ($request, $applyAll, $storeId, $amount, $recalculate, $effective, $companyKey): array {
                if ($applyAll) {
                    $productId = (string) $request->validated('product_id');
                    if ($productId === '') {
                        throw new InvalidArgumentException('Product ID wajib diisi jika menerapkan ke semua varian.');
                    }
                    $affected = $this->service->updateProductHpp($request->user('web'), $companyKey, $storeId, $productId, $amount, $recalculate, $effective);
                } else {
                    $sku = (string) $request->validated('sku');
                    if ($sku === '') {
                        throw new InvalidArgumentException('SKU wajib diisi.');
                    }
                    $affected = $this->service->updateHpp($request->user('web'), $companyKey, $storeId, $sku, $amount, $recalculate, $effective);
                }

                return [
                    'success' => true,
                    'affected_orders' => $affected,
                    'message' => "HPP tersimpan. {$affected} transaksi diperbarui. ".($recalculate ? 'Histori dihitung ulang sesuai pilihan Anda.' : 'Transaksi sebelum tanggal berlaku dipertahankan.'),
                ];
            },
            'UPDATE_GLOBAL_HPP_FAILED',
            'Gagal memperbarui HPP produk.',
            'Update global HPP failed.',
            logExceptionMessage: true,
            refusals: [DomainException::class => [403, 'STORE_FORBIDDEN'], InvalidArgumentException::class => [422, 'HPP_INPUT_INVALID']],
        ));
    }

    public function saveBatch(MarketplaceHppBatchRequest $request): JsonResponse
    {
        $result = ApiResponse::guard(
            fn (): array => $this->service->updateHppBatch(
                $request->user('web'),
                $request->companyKey(),
                (array) $request->validated('changes'),
            ),
            'SAVE_HPP_BATCH_FAILED',
            'Gagal menyimpan batch HPP.',
            'Save HPP batch failed.',
            refusals: [DomainException::class => [403, 'HPP_FORBIDDEN'], InvalidArgumentException::class => [422, 'HPP_BATCH_INVALID']],
        );
        if ($result instanceof JsonResponse) {
            return $result;
        }

        $skuCount = (int) ($result['sku_count'] ?? count((array) $request->validated('changes')));

        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => "{$skuCount} SKU tersimpan. HPP dan laba transaksi terkait sudah diperbarui.",
        ])->header('X-Request-Id', RequestId::current());
    }

    /**
     * @param  array<string, mixed>|JsonResponse  $result
     */
    private function respond(array|JsonResponse $result): JsonResponse
    {
        return $result instanceof JsonResponse ? $result : response()->json($result)->header('X-Request-Id', RequestId::current());
    }
}
