<?php

namespace App\Services\Konveksi;

use App\Auth\LegacyUser;
use App\Policies\RolePolicy;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The "Pengguna" collection is the user list of the Konveksi screen. Logging in is done by Laravel's session guard
 * against auth_users_v2 (hashed passwords), so this list must never hold a password in clear text and must stay in
 * step with the login accounts:
 *  - a password typed in the screen is hashed before it is stored (the clear text is never saved or returned);
 *  - a record sent without a password keeps the stored hash (the screen sends an empty password for "unchanged");
 *  - the hash is also written to the matching auth_users_v2 account, so the account can really log in;
 *  - an account removed from the list is deactivated (never deleted, never the one who is making the change);
 *  - only an Owner can hand out the Owner role.
 */
final class KonveksiUserAccounts
{
    public const COLLECTION = 'pengguna';

    public function __construct(private readonly KonveksiCollections $collections) {}

    public static function isHash(string $value): bool
    {
        return preg_match('/^\$(2[abxy]|argon2id?)\$/', $value) === 1;
    }

    /**
     * Records safe to store: hashed or empty passwords only. Also returns the accounts whose password changed.
     *
     * @param  list<mixed>  $incoming
     * @return array{records: list<mixed>, changed: list<array<string, mixed>>, previous: list<string>}
     */
    public function protect(array $incoming): array
    {
        $stored = $this->collections->available(self::COLLECTION) ? $this->collections->documents(self::COLLECTION) : [];
        $byId = [];
        $byName = [];
        foreach ($stored as $record) {
            if (isset($record['id']) && (string) $record['id'] !== '') {
                $byId[(string) $record['id']] = $record;
            }
            $byName[$this->name($record)] = $record;
        }

        $records = [];
        $changed = [];
        foreach ($incoming as $index => $record) {
            if (! is_array($record)) {
                $records[$index] = $record;

                continue;
            }
            $known = $byId[(string) ($record['id'] ?? '')] ?? $byName[$this->name($record)] ?? null;
            $password = (string) ($record['password'] ?? '');
            if ($password === '') {
                $record['password'] = is_array($known) ? (string) ($known['password'] ?? '') : '';
            } elseif (! self::isHash($password)) {
                $record['password'] = password_hash($password, PASSWORD_DEFAULT);
                $changed[] = $record;
            } elseif (! is_array($known) || (string) ($known['password'] ?? '') !== $password) {
                $changed[] = $record;
            }
            // A stored value that is not a hash (data from before this rule) is never trusted as a password.
            if ((string) $record['password'] !== '' && ! self::isHash((string) $record['password'])) {
                $record['password'] = '';
            }
            $records[$index] = $record;
        }

        return ['records' => $records, 'changed' => $changed, 'previous' => array_keys($byName)];
    }

    /**
     * Applies the login accounts for a saved list. Called after the collection was stored.
     *
     * @param  list<array<string, mixed>>  $changed  accounts with a new password hash
     * @param  list<mixed>  $records  the list that was stored
     * @param  list<string>  $previous  usernames in the list before this change
     */
    public function mirror(LegacyUser $actor, array $changed, array $records, array $previous): void
    {
        if (! Schema::hasTable('auth_users_v2')) {
            return;
        }
        $isOwner = RolePolicy::normalize((string) $actor->getRole()) === RolePolicy::OWNER;
        $actorName = strtolower(trim($actor->getUsername()));

        foreach ($changed as $record) {
            $username = trim((string) ($record['username'] ?? ''));
            $hash = (string) ($record['password'] ?? '');
            if ($username === '' || ! self::isHash($hash)) {
                continue;
            }
            $role = (string) ($record['role_id'] ?? '');
            if (! $isOwner && RolePolicy::normalize($role) === RolePolicy::OWNER) {
                throw new DomainException('Hanya Owner yang dapat memberikan peran Owner.');
            }
            $values = [
                'name' => trim((string) ($record['nama'] ?? '')) !== '' && $record['nama'] !== '-' ? (string) $record['nama'] : $username,
                'password_hash' => $hash,
                'role_id' => $role !== '' ? $role : 'PEMBACA',
                'status' => $this->status($record),
            ];
            $existing = DB::table('auth_users_v2')->whereRaw('LOWER(TRIM(username)) = ?', [strtolower($username)])->first();
            if ($existing !== null) {
                if (! $isOwner && RolePolicy::normalize((string) $existing->role_id) === RolePolicy::OWNER) {
                    throw new DomainException('Hanya Owner yang dapat mengubah akun Owner.');
                }
                DB::table('auth_users_v2')->where('id', $existing->id)->update($values);
            } else {
                DB::table('auth_users_v2')->insert($values + ['username' => $username]);
            }
        }

        // Status changes for accounts whose password was not touched, and removed accounts.
        $kept = [];
        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }
            $name = $this->name($record);
            $kept[$name] = true;
            $account = DB::table('auth_users_v2')->whereRaw('LOWER(TRIM(username)) = ?', [$name])->first();
            if ($account !== null && $name !== $actorName && RolePolicy::normalize((string) $account->role_id) !== RolePolicy::OWNER) {
                DB::table('auth_users_v2')->where('id', $account->id)->update(['status' => $this->status($record)]);
            }
        }
        foreach ($previous as $name) {
            if (! isset($kept[$name]) && $name !== $actorName) {
                DB::table('auth_users_v2')->whereRaw('LOWER(TRIM(username)) = ?', [$name])
                    ->where('role_id', 'NOT LIKE', '%OWNER%')->update(['status' => 'Nonaktif']);
            }
        }
    }

    /** Records for the browser: the password is blanked out (the screen reads an empty password as "unchanged"). */
    public static function redact(array $records): array
    {
        return array_map(static function ($record) {
            if (is_array($record) && array_key_exists('password', $record)) {
                $record['password'] = '';
            }

            return $record;
        }, $records);
    }

    /** @param array<string, mixed> $record */
    private function name(array $record): string
    {
        return strtolower(trim((string) ($record['username'] ?? '')));
    }

    /** @param array<string, mixed> $record */
    private function status(array $record): string
    {
        $status = strtolower(trim((string) ($record['status'] ?? 'Aktif')));

        return in_array($status, ['aktif', 'active', ''], true) ? 'Aktif' : 'Nonaktif';
    }
}
