<?php

namespace App\Auth;

use App\Policies\RolePolicy;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

final class AuthenticationService
{
    public function attempt(Request $request, string $username, string $password): ?AuthenticatedUser
    {
        $key = 'legacy-login:'.hash('sha256', strtolower(trim($username)).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Terlalu banyak percobaan masuk. Silakan coba lagi sebentar.',
                'code' => 'LOGIN_THROTTLED',
            ], 429)->header('Retry-After', (string) RateLimiter::availableIn($key)));
        }
        if (! Auth::guard('web')->attempt(['username' => $username, 'password' => $password])) {
            RateLimiter::hit($key, 60);

            return null;
        }

        RateLimiter::clear($key);

        $request->session()->forget(['workspace', 'store_id', 'workspace_filters']);
        $user = Auth::guard('web')->user();

        return $user instanceof AuthenticatedUser ? $user : null;
    }

    /**
     * Keep the response fields consumed by the old Konveksi login client.
     *
     * @return array<string, mixed>
     */
    public function legacyResult(AuthenticatedUser $user, Request $request): array
    {
        return [
            'status' => 'success',
            'role' => RolePolicy::normalize($user->getRole()) === RolePolicy::OWNER ? 'owner' : 'user',
            'token' => $request->session()->getId(),
            'nama' => $user->getDisplayName(),
            'name' => $user->getDisplayName(),
            'username' => $user->getUsername(),
            'auth_source' => $user->getSource(),
        ];
    }
}
