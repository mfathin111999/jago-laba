<?php

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Database\DatabaseManager;
use Throwable;

final class LegacyUserProvider implements UserProvider
{
    private const SOURCES = [
        'auth_users_v2',
        'system_users',
    ];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ?string $connection = null,
    ) {}

    public function retrieveById($identifier): ?Authenticatable
    {
        [$source, $id] = $this->parseIdentifier($identifier);

        if ($source !== null) {
            return $this->findById($source, $id);
        }

        foreach (['system_users', 'auth_users_v2'] as $table) {
            if ($user = $this->findById($table, (string) $identifier)) {
                return $user;
            }
        }

        return null;
    }


    public function retrieveByToken($identifier, #[\SensitiveParameter] $token): ?Authenticatable
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] $token): void
    {
        // Legacy user tables do not have a remember-token contract.
    }

    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?Authenticatable
    {
        $username = $credentials['username'] ?? $credentials['login'] ?? $credentials['email'] ?? null;

        if (! is_scalar($username) || trim((string) $username) === '') {
            return null;
        }

        foreach (self::SOURCES as $table) {
            if ($user = $this->findByUsername($table, (string) $username)) {
                return $user;
            }
        }

        return null;
    }

    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials): bool
    {
        $password = $credentials['password'] ?? null;

        return is_scalar($password)
            && $user instanceof LegacyUser
            && $user->getAuthPassword() !== ''
            && password_verify((string) $password, $user->getAuthPassword());
    }

    public function rehashPasswordIfRequired(
        Authenticatable $user,
        #[\SensitiveParameter] array $credentials,
        bool $force = false,
    ): void {
        // Rehashing would be a schema/write policy decision. Preserve the old
        // hash as read-only until that migration is explicitly approved.
    }

    private function findByUsername(string $table, string $username): ?LegacyUser
    {
        try {
            $row = $this->connection()->table($table)
                ->whereRaw('LOWER(TRIM(username)) = LOWER(TRIM(?))', [$username])
                ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) IN (?, ?)", ['aktif', 'active'])
                ->orderBy('id')
                ->first();
        } catch (Throwable) {
            return null;
        }

        return $row ? $this->makeUser($table, $row) : null;
    }

    private function findById(string $table, string $id): ?LegacyUser
    {
        if ($id === '') {
            return null;
        }

        try {
            $row = $this->connection()->table($table)
                ->where('id', $id)
                ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) IN (?, ?)", ['aktif', 'active'])
                ->first();
        } catch (Throwable) {
            return null;
        }

        return $row ? $this->makeUser($table, $row) : null;
    }

    private function connection()
    {
        return $this->database->connection($this->connection);
    }

    private function makeUser(string $source, object $row): LegacyUser
    {
        $attributes = get_object_vars($row);
        $attributes['source'] = $source;
        $attributes['auth_identifier'] = 'legacy:'.$source.':'.(string) ($attributes['id'] ?? '');

        return new LegacyUser($attributes);
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function parseIdentifier($identifier): array
    {
        $value = trim((string) $identifier);

        if (preg_match('/^legacy:(auth_users_v2|system_users):(.+)$/D', $value, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        if (str_starts_with($value, 'AUTHV2-')) {
            return ['auth_users_v2', substr($value, 7)];
        }

        return [null, $value];
    }
}
