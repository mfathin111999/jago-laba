<?php

namespace App\Console\Commands;

use App\Services\Konveksi\KonveksiCollections;
use App\Services\Konveksi\KonveksiUserAccounts;
use Illuminate\Console\Command;

/**
 * One-off cleanup for data created before the user list stopped keeping clear-text passwords: every stored
 * Pengguna password that is not a hash is emptied. Those values never worked for login (the session guard checks
 * auth_users_v2), so nobody loses access; the account gets a password again from the user screen.
 */
final class KonveksiSecurePasswordsCommand extends Command
{
    protected $signature = 'konveksi:secure-passwords {--dry-run : Only count}';

    protected $description = 'Empty clear-text passwords left in the Konveksi user list';

    public function handle(KonveksiCollections $collections): int
    {
        if (! $collections->available(KonveksiUserAccounts::COLLECTION)) {
            $this->warn('Koleksi pengguna belum ada.');

            return self::SUCCESS;
        }

        $cleaned = 0;
        foreach ($collections->rows(KonveksiUserAccounts::COLLECTION) as $row) {
            $password = (string) ($row['data']['password'] ?? '');
            if ($password === '' || KonveksiUserAccounts::isHash($password)) {
                continue;
            }
            $cleaned++;
            if (! $this->option('dry-run')) {
                $collections->update(KonveksiUserAccounts::COLLECTION, $row['id'], ['password' => ''] + $row['data']);
            }
        }

        $this->info(($this->option('dry-run') ? 'Akan dibersihkan: ' : 'Dibersihkan: ').$cleaned.' password polos.');

        return self::SUCCESS;
    }
}
