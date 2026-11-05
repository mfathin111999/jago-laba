<?php

namespace App\Http\Middleware;

use App\Authorization\RequestContext;
use App\Authorization\StoreContext;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureStoreContext
{
    public function __construct(private readonly StoreContext $store) {}

    public function handle(Request $request, Closure $next, string $parameter = 'store_id'): Response
    {
        $user = Auth::guard('web')->user();

        if (! $user instanceof Authenticatable) {
            return response()->json(['message' => 'Autentikasi diperlukan.', 'code' => 'AUTH_REQUIRED'], 401);
        }

        $value = $request->route($parameter) ?? $request->input($parameter) ?? $request->header('X-Store-Id');
        $storeId = is_scalar($value) ? trim((string) $value) : '';

        if ($storeId === '' || ! $this->store->canAccess($user, $storeId)) {
            return response()->json(['message' => 'Store tidak diizinkan.', 'code' => 'STORE_FORBIDDEN'], 403);
        }

        $request->attributes->set('store_id', $storeId);
        app(RequestContext::class)->setStoreId($storeId);
        $request->session()->put('store_id', $storeId);

        return $next($request);
    }
}
