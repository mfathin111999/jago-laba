<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarketplaceStoreCreateRequest;
use App\Http\Requests\MarketplaceStoreToggleRequest;
use App\Http\Requests\MarketplaceStoreUpdateRequest;
use App\Http\Responses\ApiResponse;
use App\Pilots\Marketplace\MarketplaceStoreWriteService;
use App\Support\RequestId;
use Illuminate\Http\JsonResponse;

/**
 * Store writes. The service throws typed refusals (StoreForbidden, ResourceNotFound, InvalidInput) which the exception
 * handler renders; anything unexpected becomes the endpoint's own failure code through ApiResponse::guard().
 */
final class MarketplaceStoreWriteController
{
    public function __construct(private readonly MarketplaceStoreWriteService $service) {}

    public function create(MarketplaceStoreCreateRequest $request): JsonResponse
    {
        return $this->respond(ApiResponse::guard(
            fn (): array => $this->service->create($request->user('web'), $this->companyKey($request), $request->validated()),
            'CREATE_STORE_FAILED',
            'Gagal menambahkan toko baru.',
            'Create store failed.',
            logExceptionMessage: true,
        ));
    }

    public function update(MarketplaceStoreUpdateRequest $request): JsonResponse
    {
        return $this->respond(ApiResponse::guard(
            fn (): array => $this->service->update($request->user('web'), $this->companyKey($request), (string) $request->validated('store_id'), $request->validated()),
            'UPDATE_STORE_FAILED',
            'Gagal memperbarui toko.',
            'Update store failed.',
        ));
    }

    public function toggleStatus(MarketplaceStoreToggleRequest $request): JsonResponse
    {
        return $this->respond(ApiResponse::guard(
            fn (): array => $this->service->toggleStatus($request->user('web'), $this->companyKey($request), (string) $request->validated('store_id'), (string) $request->validated('status')),
            'TOGGLE_STATUS_FAILED',
            'Gagal mengubah status toko.',
            'Toggle store status failed.',
        ));
    }

    public function archive(MarketplaceStoreToggleRequest $request): JsonResponse
    {
        return $this->respond(ApiResponse::guard(
            fn (): array => $this->service->archive($request->user('web'), $this->companyKey($request), (string) $request->validated('store_id')),
            'ARCHIVE_STORE_FAILED',
            'Gagal mengarsipkan toko.',
            'Archive store failed.',
        ));
    }

    public function restore(MarketplaceStoreToggleRequest $request): JsonResponse
    {
        return $this->respond(ApiResponse::guard(
            fn (): array => $this->service->restore($request->user('web'), $this->companyKey($request), (string) $request->validated('store_id')),
            'RESTORE_STORE_FAILED',
            'Gagal memulihkan toko.',
            'Restore store failed.',
        ));
    }

    /**
     * @param  array<string, mixed>|JsonResponse  $result
     */
    private function respond(array|JsonResponse $result): JsonResponse
    {
        return $result instanceof JsonResponse ? $result : response()->json($result)->header('X-Request-Id', RequestId::current());
    }

    private function companyKey(MarketplaceStoreUpdateRequest|MarketplaceStoreCreateRequest|MarketplaceStoreToggleRequest $request): string
    {
        return (string) $request->attributes->get('company_key');
    }
}
