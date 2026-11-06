<?php

namespace App\Http\Responses;

use App\Support\RequestId;
use Illuminate\Http\JsonResponse;

final class UnavailableResponse
{
    public static function unavailable(string $contract): JsonResponse
    {
        $requestId = RequestId::current();
        $body = $contract === 'rpc'
            ? ['ok' => false, 'error' => ['message' => 'Endpoint belum dialihkan ke Laravel.', 'request_id' => $requestId]]
            : ['success' => false, 'message' => 'Endpoint belum dialihkan ke Laravel.', 'request_id' => $requestId];

        return response()->json($body, 503)
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Request-Id', $requestId);
    }
}
