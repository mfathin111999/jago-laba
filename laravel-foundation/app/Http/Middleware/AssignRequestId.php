<?php

namespace App\Http\Middleware;

use App\Support\RequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request one id: a valid X-Request-Id sent by the client is kept, otherwise a new one is created.
 * It is added to the log Context (so every log line carries it), stored as the `request_id` request attribute and
 * returned in the X-Request-Id response header unless a controller already set one.
 */
final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = trim((string) $request->headers->get('X-Request-Id', ''));
        $id = RequestId::isValid($incoming) ? $incoming : RequestId::generate();

        Context::add('request_id', $id);
        $request->attributes->set('request_id', $id);

        $response = $next($request);

        if (! $response->headers->has('X-Request-Id')) {
            $response->headers->set('X-Request-Id', $id);
        }

        return $response;
    }
}
