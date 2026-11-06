<?php

namespace App\Http\Controllers;

use App\Auth\LegacyAuthenticationService;
use App\Auth\LegacyUser;
use App\Authorization\CompanyContext;
use App\Authorization\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class AuthController
{
    public function __construct(
        private readonly LegacyAuthenticationService $authentication,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'max:4096'],
        ]);

        $user = $this->authentication->attempt($request, $data['username'], $data['password']);

        if (! $user) {
            return response()->json([
                'message' => 'Username atau password tidak valid.',
                'code' => 'AUTH_INVALID_CREDENTIALS',
            ], 401);
        }

        return response()->json([
            'status' => 'success',
            'user' => $user->toArray(),
            'workspace' => null,
        ]);
    }

    public function me(Request $request, WorkspaceContext $workspace, CompanyContext $company): JsonResponse
    {
        $user = Auth::guard('web')->user();

        if (! $user instanceof LegacyUser) {
            return response()->json(['message' => 'Autentikasi diperlukan.', 'code' => 'AUTH_REQUIRED'], 401);
        }

        return response()->json([
            'user' => $user->toArray(),
            'context' => [
                'company_key' => $company->key($user),
                'workspace' => $workspace->active($user),
                'allowed_workspaces' => $workspace->allowed($user),
                'store_id' => $request->session()->get('store_id'),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['status' => 'success']);
    }
}
