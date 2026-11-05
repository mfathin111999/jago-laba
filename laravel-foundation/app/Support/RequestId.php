<?php

namespace App\Support;

use Illuminate\Support\Facades\Context;

/**
 * The id that ties one request to its response header, JSON body and log lines.
 * AssignRequestId puts it in the log Context at the start of the request; outside a request (queue, console) a
 * fresh id is created on demand.
 */
final class RequestId
{
    /** Same shape the browser clients and the sync endpoint already accept. */
    public const PATTERN = '/^[A-Za-z0-9._:-]{1,100}$/';

    public static function current(): string
    {
        $id = Context::get('request_id');
        if (! is_string($id) || $id === '') {
            $id = self::generate();
            Context::add('request_id', $id);
        }

        return $id;
    }

    public static function generate(): string
    {
        return bin2hex(random_bytes(8));
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}
