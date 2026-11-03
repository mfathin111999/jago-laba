<?php

namespace App\Http\Middleware;

use App\Http\Responses\LegacyResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureLegacyMarketplaceExpenseAction
{
    public function handle(Request $request, Closure $next): Response
    {
        $action = trim((string) ($request->input('action') ?: $request->query('action', '')));

        if (! in_array($action, ['add_expense', 'delete_expense'], true)) {
            return LegacyResponse::unavailable('rpc');
        }

        return $next($request);
    }
}
