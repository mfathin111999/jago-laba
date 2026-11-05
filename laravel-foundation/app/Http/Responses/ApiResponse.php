<?php

namespace App\Http\Responses;

use App\Exceptions\ApiException;
use App\Exceptions\IdempotencyConflict;
use App\Support\RequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one place that builds the JSON shape of the Marketplace/legacy endpoints:
 * `{success:false, message, code, request_id}` plus the X-Request-Id header.
 */
final class ApiResponse
{
    public static function error(string $message, string $code, int $status): JsonResponse
    {
        $requestId = RequestId::current();

        return response()->json([
            'success' => false,
            'message' => $message,
            'code' => $code,
            'request_id' => $requestId,
        ], $status)->header('X-Request-Id', $requestId);
    }

    /**
     * Runs the action. Typed refusals (ApiException, IdempotencyConflict) propagate to the exception handler, which
     * renders them; any other failure is logged without its message and answered with the endpoint's own failure code.
     *
     * @template T
     *
     * @param  callable(): T  $action
     * @param  array<class-string<Throwable>, array{0: int, 1: string}>  $refusals  exceptions the endpoint answers with their own
     *                                                                              message, as [status, code]; checked in order
     * @return T|JsonResponse
     */
    public static function guard(callable $action, string $failureCode, string $failureMessage, string $logMessage, bool $logExceptionMessage = false, array $refusals = []): mixed
    {
        try {
            return $action();
        } catch (ApiException|IdempotencyConflict $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            foreach ($refusals as $class => [$status, $code]) {
                if ($exception instanceof $class) {
                    return self::error($exception->getMessage(), $code, $status);
                }
            }

            Log::error($logMessage, [
                'request_id' => RequestId::current(),
                'exception' => $exception::class,
            ] + ($logExceptionMessage ? ['message' => $exception->getMessage()] : []));

            return self::error($failureMessage, $failureCode, 500);
        }
    }
}
