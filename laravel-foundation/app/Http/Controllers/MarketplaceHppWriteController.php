<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarketplaceGlobalHppUpdateRequest;
use App\Http\Requests\MarketplaceHppBatchRequest;
use App\Http\Requests\MarketplaceSkuHppUpdateRequest;
use App\Pilots\Marketplace\MarketplaceHppService;
use App\Support\RequestId;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class MarketplaceHppWriteController
{
    public function __construct(private readonly MarketplaceHppService $service) {}

    public function updateSku(MarketplaceSkuHppUpdateRequest $request): JsonResponse
    {
        $requestId = RequestId::current();

        try {
            $result = $this->service->updateSkuHpp(
                $request->user('web'),
                (string) $request->attributes->get('company_key'),
                (string) $request->validated('store_id'),
                (string) $request->validated('sku'),
                (float) $request->validated('hpp'),
                $request->validated('product_variant_id'),
            );
        } catch (DomainException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => 'STORE_FORBIDDEN',
                'request_id' => $requestId,
            ], 403)->header('X-Request-Id', $requestId);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => 'HPP_INPUT_INVALID',
                'request_id' => $requestId,
            ], 422)->header('X-Request-Id', $requestId);
        } catch (Throwable $exception) {
            Log::error('Update SKU HPP failed.', ['request_id' => $requestId, 'exception' => $exception::class, 'message' => $exception->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui HPP SKU.',
                'code' => 'UPDATE_HPP_FAILED',
                'request_id' => $requestId,
            ], 500)->header('X-Request-Id', $requestId);
        }

        return response()->json($result)->header('X-Request-Id', $requestId);
    }

    public function updateGlobal(MarketplaceGlobalHppUpdateRequest $request): JsonResponse
    {
        $requestId = RequestId::current();
        $applyAll = (bool) $request->validated('apply_all_variants');
        $storeId = (string) $request->validated('store_id');
        $amount = (string) $request->validated('hpp');
        $recalculate = (bool) $request->validated('recalculate_history');
        $effective = $request->validated('effective_from');

        try {
            if ($applyAll) {
                $productId = (string) $request->validated('product_id');
                if ($productId === '') {
                    throw new InvalidArgumentException('Product ID wajib diisi jika menerapkan ke semua varian.');
                }
                $affected = $this->service->updateProductHpp(
                    $request->user('web'),
                    (string) $request->attributes->get('company_key'),
                    $storeId,
                    $productId,
                    $amount,
                    $recalculate,
                    $effective,
                );
            } else {
                $sku = (string) $request->validated('sku');
                if ($sku === '') {
                    throw new InvalidArgumentException('SKU wajib diisi.');
                }
                $affected = $this->service->updateHpp(
                    $request->user('web'),
                    (string) $request->attributes->get('company_key'),
                    $storeId,
                    $sku,
                    $amount,
                    $recalculate,
                    $effective,
                );
            }

            $result = [
                'success' => true,
                'affected_orders' => $affected,
                'message' => "HPP tersimpan. {$affected} transaksi diperbarui. ".($recalculate ? 'Histori dihitung ulang sesuai pilihan Anda.' : 'Transaksi sebelum tanggal berlaku dipertahankan.'),
            ];
        } catch (DomainException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => 'STORE_FORBIDDEN',
                'request_id' => $requestId,
            ], 403)->header('X-Request-Id', $requestId);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => 'HPP_INPUT_INVALID',
                'request_id' => $requestId,
            ], 422)->header('X-Request-Id', $requestId);
        } catch (Throwable $exception) {
            Log::error('Update global HPP failed.', ['request_id' => $requestId, 'exception' => $exception::class, 'message' => $exception->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui HPP produk.',
                'code' => 'UPDATE_GLOBAL_HPP_FAILED',
                'request_id' => $requestId,
            ], 500)->header('X-Request-Id', $requestId);
        }

        return response()->json($result)->header('X-Request-Id', $requestId);
    }

    public function saveBatch(MarketplaceHppBatchRequest $request): JsonResponse
    {
        $requestId = RequestId::current();

        try {
            $result = $this->service->updateHppBatch(
                $request->user('web'),
                (string) $request->attributes->get('company_key'),
                (array) $request->validated('changes'),
            );
        } catch (DomainException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => 'HPP_FORBIDDEN',
                'request_id' => $requestId,
            ], 403)->header('X-Request-Id', $requestId);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => 'HPP_BATCH_INVALID',
                'request_id' => $requestId,
            ], 422)->header('X-Request-Id', $requestId);
        } catch (Throwable $exception) {
            Log::error('Save HPP batch failed.', ['request_id' => $requestId, 'exception' => $exception::class]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan batch HPP.',
                'code' => 'SAVE_HPP_BATCH_FAILED',
                'request_id' => $requestId,
            ], 500)->header('X-Request-Id', $requestId);
        }

        $skuCount = (int) ($result['sku_count'] ?? count((array) $request->validated('changes')));

        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => "{$skuCount} SKU tersimpan. HPP dan laba transaksi terkait sudah diperbarui.",
        ])->header('X-Request-Id', $requestId);
    }
}
