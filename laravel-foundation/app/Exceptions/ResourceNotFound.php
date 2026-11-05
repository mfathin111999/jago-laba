<?php

namespace App\Exceptions;

/** A record the request refers to does not exist in the active scope. HTTP 404 with a caller-specific code. */
final class ResourceNotFound extends ApiException
{
    public function __construct(string $message, string $errorCode)
    {
        parent::__construct($message, $errorCode, 404);
    }
}
