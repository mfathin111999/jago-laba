<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * A refusal the client should see as `{success:false, message, code, request_id}` with a fixed HTTP status.
 *
 * It extends DomainException and keeps the HTTP status as the exception code, so existing `catch (\DomainException)`
 * blocks and `getCode()` checks keep working while services move to typed exceptions. Laravel calls render() when the
 * exception reaches the handler, so controllers no longer need their own try/catch for it.
 */
abstract class ApiException extends DomainException
{
    public function __construct(string $message, private readonly string $errorCode, private readonly int $status, ?Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function render(Request $request): JsonResponse
    {
        return ApiResponse::error($this->getMessage(), $this->errorCode, $this->status);
    }
}
