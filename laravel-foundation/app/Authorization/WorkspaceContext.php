<?php

namespace App\Authorization;

use App\Auth\AuthenticatedUser;
use App\Policies\RolePolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

final class WorkspaceContext
{
    public const KONVEKSI = 'konveksi';

    public const MARKETPLACE = 'marketplace';

    public function __construct(private readonly DatabaseManager $database) {}

    /**
     * @return list<string>
     */
    public function allowed(?Authenticatable $user): array
    {
        $legacyUser = $user instanceof AuthenticatedUser ? $user : null;

        if (! $legacyUser) {
            return [];
        }

        $defaults = in_array(RolePolicy::normalize($legacyUser->getRole()), [RolePolicy::OWNER, RolePolicy::ADMINISTRATOR], true)
            ? [self::KONVEKSI, self::MARKETPLACE]
            : [self::KONVEKSI];

        try {
            $rows = $this->grantedWorkspaces($legacyUser)
                ->map(static fn ($workspace): string => strtolower(trim((string) $workspace)))
                ->filter(static fn (string $workspace): bool => in_array($workspace, [self::KONVEKSI, self::MARKETPLACE], true))
                ->unique()
                ->values()
                ->all();

            return $rows !== [] ? array_values($rows) : $defaults;
        } catch (Throwable) {
            return $defaults;
        }
    }

    public function can(?Authenticatable $user, string $workspace): bool
    {
        return in_array(self::normalize($workspace), $this->allowed($user), true);
    }

    public function active(?Authenticatable $user): ?string
    {
        if (! $user instanceof AuthenticatedUser) {
            return null;
        }

        $workspace = strtolower(trim((string) session()->get('workspace', $user->getWorkspace() ?? '')));

        return $workspace !== '' && $this->can($user, $workspace) ? $workspace : null;
    }

    public function select(?Authenticatable $user, string $workspace): string
    {
        $workspace = self::normalize($workspace);

        if (! in_array($workspace, [self::KONVEKSI, self::MARKETPLACE], true)) {
            throw new InvalidArgumentException('Workspace tidak dikenal.');
        }

        if (! $this->can($user, $workspace)) {
            throw new AuthorizationException('Workspace tidak diizinkan.');
        }

        session()->put('workspace', $workspace);
        session()->forget(['store_id', 'workspace_filters']);

        return $workspace;
    }

    public static function normalize(string $workspace): string
    {
        return strtolower(trim($workspace));
    }

    /**
     * Explicit grants. The table exists in two shapes: the reviewed legacy DDL (workspace_code + is_allowed, where a
     * row may revoke) and the simpler (workspace) form. Both are honoured; anything else falls back to the defaults.
     *
     * @return Collection<int, mixed>
     */
    private function grantedWorkspaces(AuthenticatedUser $user): Collection
    {
        $connection = $this->database->connection();
        $query = $connection->table('workspace_user_access')->whereIn('user_key', $user->legacyKeyCandidates());
        $schema = $connection->getSchemaBuilder();

        if ($schema->hasColumn('workspace_user_access', 'workspace_code')) {
            if ($schema->hasColumn('workspace_user_access', 'is_allowed')) {
                $query->where('is_allowed', 1);
            }

            return $query->pluck('workspace_code');
        }

        return $query->pluck('workspace');
    }
}
