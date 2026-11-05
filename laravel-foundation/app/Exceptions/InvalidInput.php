<?php

namespace App\Exceptions;

/** The request was understood but its content is refused (business rule or input). HTTP 422 with a caller-specific code. */
final class InvalidInput extends ApiException
{
    public function __construct(string $message, string $errorCode)
    {
        parent::__construct($message, $errorCode, 422);
    }
}
