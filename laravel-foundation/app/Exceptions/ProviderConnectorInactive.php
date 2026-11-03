<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The request is valid, but completing it needs a live marketplace provider connector that is not enabled.
 * Callers answer HTTP 503 with error_code PROVIDER_CONNECTOR_INACTIVE and must not change any local state.
 */
final class ProviderConnectorInactive extends RuntimeException
{
    public const CODE = 'PROVIDER_CONNECTOR_INACTIVE';
}
