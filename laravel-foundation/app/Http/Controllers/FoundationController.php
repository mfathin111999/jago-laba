<?php

namespace App\Http\Controllers;

use App\Auth\LegacyUser;
use App\Authorization\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class FoundationController
{
    public function __invoke(Request $request, WorkspaceContext $workspace): Response|RedirectResponse
    {
        $user = Auth::guard('web')->user();

        // The front controller reads a request for /index.php (the legacy Konveksi URL) as "/": send signed-in users on.
        if ($user instanceof LegacyUser && parse_url((string) $request->server('REQUEST_URI'), PHP_URL_PATH) === '/index.php' && $workspace->active($user) === WorkspaceContext::KONVEKSI) {
            return redirect('/konveksi', 303);
        }

        return Inertia::render('Foundation', [
            'title' => 'Jago Laba Laravel Foundation',
            'status' => 'read-only',
            'authenticated' => $user instanceof LegacyUser,
            'user' => $user instanceof LegacyUser ? $user->toArray() : null,
            'workspaces' => $workspace->allowed($user),
            'active_workspace' => $workspace->active($user),
        ]);
    }
}
