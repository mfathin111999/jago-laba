<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarketplaceStoreCreateRequest;
use App\Http\Requests\MarketplaceStoreToggleRequest;
use App\Http\Requests\MarketplaceStoreUpdateRequest;
use App\Pilots\Marketplace\MarketplaceStoreWriteService;
use App\Support\RequestId;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Throwable;

final class MarketplaceStoreWriteController
{
    public function __construct(private readonly MarketplaceStoreWriteService $service) {}

    public function create(MarketplaceStoreCreateRequest $request): JsonResponse
    {
        $requestId = RequestId::current();

        try {
            $result = $this->service->create(
                $request->user('web'),
                (string) $request->attributes->get('company_key'),
                $request->validated(),
            );
        } catch (InvalidArgumentException|LogicException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => 'STORE_INPUT_INVALID',
                'request_id' => $requestId,
            ], 422)->header('X-Request-Id', $requestId);
        } catch (Throwable $exception) {
            Log::error('Create store failed.', ['request_id' => $requestId, 'exception' => $exception::class, 'message' => $exception->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan toko baru.',
                'code' => 'CREATE_STORE_FAILED',
                'request_id' => $requestId,
            ], 500)->header('X-Request-Id', $requestId);
        }

        return response()->json($result)->header('X-Request-Id', $requestId);
    }

    public function update(MarketplaceStoreUpdateRequest $request): JsonResponse
    {
        $requestId = RequestId::current();
        $storeId = (string) $request->validated('store_id');

        try {
            $result = $this->service->update(
                $request->user('web'),
                (string) $request->attributes->get('company_key'),
                $storeId,
                $request->validated(),
            );
        } catch (DomainException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => 'STORE_FORBIDDEN',
                'request_id' => $requestId,
            ], 403)->header('X-Request-Id', $requestId);
        } catch (InvalidArgumentException|LogicException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => 'STORE_INPUT_INVALID',
                'request_id' => $requestId,
            ], 422)->header('X-Request-Id', $requestId);
        } catch (Throwable $exception) {
            Log::error('Update store failed.', ['request_id' => $requestId, 'exception' => $exception::class]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui toko.',
                'code' => 'UPDATE_STORE_FAILED',
                'request_id' => $requestId,
            ], 500)->header('X-Request-Id', $requestId);
        }

        return response()->json($result)->header('X-Request-Id', $requestId);
    }

    public function toggleStatus(MarketplaceStoreToggleRequest $request): JsonResponse
    {
        $requestId = RequestId::current();
        $storeId = (string) $request->validated('store_id');
        $status = (string) $request->validated('status');

        try {
            $result = $this->service->toggleStatus(
                $request->user('web'),
                (string) $request->attributes->get('company_key'),
                $storeId,
                $status,
            );
        } catch (DomainException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => 'STORE_FORBIDDEN',
                'request_id' => $requestId,
            ], 403)->header('X-Request-Id', $requestId);
        } catch (LogicException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => 'STORE_STATUS_INVALID',
                'request_id' => $requestId,
            ], 422)->header('X-Request-Id', $requestId);
        } catch (Throwable $exception) {
            Log::error('Toggle store status failed.', ['request_id' => $requestId, 'exception' => $exception::class]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengubah status toko.',
                'code' => 'TOGGLE_STATUS_FAILED',
                'request_id' => $requestId,
            ], 500)->header('X-Request-Id', $requestId);
        }

        return response()->json($result)->header('X-Request-Id', $requestId);
    }

    public function archive(MarketplaceStoreToggleRequest $request): JsonResponse
    {
        $requestId = RequestId::current();
        $storeId = (string) $request->validated('store_id');

        try {
            $result = $this->service->archive(
                $request->user('web'),
                (string) $request->attributes->get('company_key'),
                $storeId,
            );
        } catch (DomainException $exception) {
            $status = $exception->getCode() === 404 ? 404 : 403;

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => $status === 404 ? 'STORE_NOT_FOUND' : 'STORE_FORBIDDEN',
                'request_id' => $requestId,
            ], $status)->header('X-Request-Id', $requestId);
        } catch (Throwable $exception) {
            Log::error('Archive store failed.', ['request_id' => $requestId, 'exception' => $exception::class]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengarsipkan toko.',
                'code' => 'ARCHIVE_STORE_FAILED',
                'request_id' => $requestId,
            ], 500)->header('X-Request-Id', $requestId);
        }

        return response()->json($result)->header('X-Request-Id', $requestId);
    }

    public function restore(MarketplaceStoreToggleRequest $request): JsonResponse
    {
        $requestId = RequestId::current();
        $storeId = (string) $request->validated('store_id');

        try {
            $result = $this->service->restore(
                $request->user('web'),
                (string) $request->attributes->get('company_key'),
                $storeId,
            );
        } catch (DomainException $exception) {
            $status = $exception->getCode() === 404 ? 404 : 403;

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => $status === 404 ? 'STORE_NOT_FOUND' : 'STORE_FORBIDDEN',
                'request_id' => $requestId,
            ], $status)->header('X-Request-Id', $requestId);
        } catch (Throwable $exception) {
            Log::error('Restore store failed.', ['request_id' => $requestId, 'exception' => $exception::class]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memulihkan toko.',
                'code' => 'RESTORE_STORE_FAILED',
                'request_id' => $requestId,
            ], 500)->header('X-Request-Id', $requestId);
        }

        return response()->json($result)->header('X-Request-Id', $requestId);
    }
}
