<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates or resets the Owner login (the replacement of SchemaManager::ensureOwner). Only the login rows change;
 * no business data is touched. The canonical row is auth_users_v2; a legacy system_users row is added when absent.
 */
final class CreateOwnerCommand extends Command
{
    private const ROLE = 'ROLE-OWNER_SUPER_ADMIN';

    protected $signature = 'jago:create-owner
        {--username=owner : Login name}
        {--name=Owner JAGO LABA : Display name}
        {--password= : Password (asked for when omitted)}';

    protected $description = 'Create the Owner login, or reset its password and activate it';

    public function handle(): int
    {
        $username = trim((string) $this->option('username')) ?: 'owner';
        $name = trim((string) $this->option('name')) ?: 'Owner JAGO LABA';
        $password = (string) $this->option('password');
        if ($password === '' && $this->input->isInteractive()) {
            $password = (string) $this->secret('Password untuk '.$username);
        }
        if ($password === '') {
            $this->error('Password wajib diisi (--password= atau lewat prompt).');

            return self::FAILURE;
        }
        if (strlen($password) < 8 && app()->environment('production')) {
            $this->error('Di production password minimal 8 karakter.');

            return self::FAILURE;
        }
        if (! Schema::hasTable('auth_users_v2')) {
            $this->error('Tabel auth_users_v2 belum ada. Jalankan `php artisan migrate` lebih dulu.');

            return self::FAILURE;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        DB::transaction(function () use ($username, $name, $hash): void {
            $values = ['name' => $name, 'password_hash' => $hash, 'role_id' => self::ROLE, 'status' => 'Aktif'];
            DB::table('auth_users_v2')->updateOrInsert(['username' => $username], $values);

            if (Schema::hasTable('system_users') && ! DB::table('system_users')->whereRaw('LOWER(TRIM(username)) = ?', [strtolower($username)])->exists()) {
                DB::table('system_users')->insert($values + ['username' => $username, 'must_change_password' => 0]);
            }
        });

        $this->info("Login Owner '{$username}' siap dipakai.");

        return self::SUCCESS;
    }
}
