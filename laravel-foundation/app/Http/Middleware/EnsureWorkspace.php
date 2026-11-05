<?php

namespace App\Http\Middleware;

use App\Authorization\RequestContext;
use App\Authorization\WorkspaceContext;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureWorkspace
{
    public function __construct(private readonly WorkspaceContext $workspace) {}

    public function handle(Request $request, Closure $next, ?string $required = null): Response
    {
        $user = Auth::guard('web')->user();

        if (! $user instanceof Authenticatable) {
            return response()->json(['message' => 'Autentikasi diperlukan.', 'code' => 'AUTH_REQUIRED'], 401);
        }

        $required = $required !== null ? WorkspaceContext::normalize($required) : $this->workspace->active($user);

        if ($required === null || ! $this->workspace->can($user, $required)) {
            return response()->json(['message' => 'Workspace tidak diizinkan.', 'code' => 'WORKSPACE_FORBIDDEN'], 403);
        }

        if ($this->workspace->active($user) !== $required) {
            return response()->json(['message' => 'Workspace belum dipilih.', 'code' => 'WORKSPACE_NOT_ACTIVE'], 403);
        }

        $request->attributes->set('workspace', $required);
        app(RequestContext::class)->setWorkspace($required);

        return $next($request);
    }
}
