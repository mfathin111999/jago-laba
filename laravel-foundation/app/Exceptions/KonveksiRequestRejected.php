<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A Konveksi write was refused before or while running (missing idempotency key, stale data, busy lock...)
 * or the wrapped operation produced an error response that must be returned untouched.
 */
final class KonveksiRequestRejected extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
        public readonly ?JsonResponse $response = null,
    ) {
        parent::__construct($message);
    }

    public static function passthrough(JsonResponse $response): self
    {
        return new self('Operasi menghasilkan respons galat.', 'PASSTHROUGH', $response->getStatusCode(), $response);
    }
}
