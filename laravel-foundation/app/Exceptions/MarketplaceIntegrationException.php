<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A marketplace platform call failed or answered something unusable. The message is safe to show; `errorCode` is a
 * machine identifier (HTTP_429, SHOPEE_ERROR_AUTH, TIKTOK_API_105001, ...) that IntegrationPolicy::classify() buckets.
 */
final class MarketplaceIntegrationException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'MARKETPLACE_API_ERROR', public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct($message);
    }
}
