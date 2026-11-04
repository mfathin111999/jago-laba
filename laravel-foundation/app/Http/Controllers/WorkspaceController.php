<?php

namespace App\Http\Controllers;

use App\Auth\LegacySessionStore;
use App\Authorization\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class WorkspaceController
{
    public function __construct(
        private readonly WorkspaceContext $workspace,
        private readonly LegacySessionStore $legacySession,
    ) {}

    public function select(Request $request): JsonResponse
    {
        $value = $request->input('workspace');

        if (! is_scalar($value) || trim((string) $value) === '') {
            return response()->json(['success' => false, 'message' => 'Workspace wajib dipilih.'], 422);
        }

        try {
            $selected = $this->workspace->select($request->user('web'), (string) $value);
        } catch (InvalidArgumentException|AuthorizationException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 403);
        }

        $this->legacySession->syncWorkspace($request, $selected);

        return response()->json([
            'success' => true,
            'workspace' => $selected,
            'url' => $selected === WorkspaceContext::MARKETPLACE ? 'marketplace.php' : 'konveksi',
        ]);
    }
}
