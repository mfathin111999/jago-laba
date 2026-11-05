<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** The idempotency key was already used for a different request. HTTP 409, code IDEMPOTENCY_CONFLICT. */
final class IdempotencyConflict extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return ApiResponse::error($this->getMessage(), 'IDEMPOTENCY_CONFLICT', 409);
    }
}
