<?php

namespace App\Console\Commands;

use App\Services\Konveksi\KonveksiConnection;
use App\Services\Konveksi\Typed\KonveksiTypedMigrator;
use App\Services\Konveksi\TypedCollections;
use Illuminate\Console\Command;
use Throwable;

/**
 * Moves Konveksi collections between the JSON layout and typed tables.
 *
 *   konveksi:typed status                 which layout each collection uses
 *   konveksi:typed migrate --all          create tables, copy, verify record by record, then switch
 *   konveksi:typed migrate rekening       the same for chosen collections
 *   konveksi:typed rollback --all         copy typed rows back to the JSON tables and switch back
 *
 * Nothing is switched unless the verification passes; the JSON tables stay as an archive.
 */
final class KonveksiTypedCommand extends Command
{
    protected $signature = 'konveksi:typed
        {action : status, migrate or rollback}
        {keys?* : Collection keys}
        {--all : Every collection that has a typed definition}
        {--no-switch : migrate: copy and verify but keep reading the JSON tables}
        {--replace : migrate: overwrite a typed table that already holds rows}';

    protected $description = 'Move Konveksi collections to typed MySQL tables (and back)';

    public function handle(KonveksiTypedMigrator $migrator, TypedCollections $typed, KonveksiConnection $konveksi): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, ['status', 'migrate', 'rollback'], true)) {
            $this->error('Aksi harus status, migrate, atau rollback.');

            return self::INVALID;
        }

        $keys = $this->option('all') || $this->argument('keys') === [] ? array_keys($typed->definitions()) : (array) $this->argument('keys');
        $unknown = array_diff($keys, array_keys($typed->definitions()));
        if ($unknown !== []) {
            $this->error('Belum ada definisi bertipe untuk: '.implode(', ', $unknown));

            return self::FAILURE;
        }

        if ($action === 'status') {
            $this->table(['Koleksi', 'Layout', 'Tabel', 'Baris'], array_map(static fn (string $key): array => array_values($migrator->status($key)), $keys));

            return self::SUCCESS;
        }
        if ($action === 'migrate' && ! $this->option('all') && $this->argument('keys') === []) {
            $this->error('Sebutkan koleksi atau gunakan --all.');

            return self::FAILURE;
        }

        $lock = 'jl-konveksi-write-'.sha1((string) $konveksi->connection()->getDatabaseName());
        $locked = $this->lock($konveksi, $lock);
        $failed = false;
        try {
            foreach ($keys as $key) {
                try {
                    $failed = ! $this->apply($action, $migrator, $key) || $failed;
                } catch (Throwable $exception) {
                    $this->error("{$key}: ".$exception->getMessage());
                    $failed = true;
                }
            }
        } finally {
            if ($locked) {
                $konveksi->connection()->select('SELECT RELEASE_LOCK(?)', [$lock]);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function apply(string $action, KonveksiTypedMigrator $migrator, string $key): bool
    {
        if ($action === 'rollback') {
            $migrator->rollbackToJson($key);
            $this->info("{$key}: kembali ke tabel JSON.");

            return true;
        }

        $result = $migrator->backfill($key, (bool) $this->option('replace'));
        if ($this->option('no-switch')) {
            $this->info("{$key}: {$result['rows']} baris disalin dan terverifikasi (belum dialihkan).");

            return true;
        }
        $migrator->switchToTyped($key);
        $this->info(sprintf('%s: %d baris terverifikasi, kini memakai tabel bertipe (%d baris menyimpan nilai asli).', $key, $result['rows'], $result['with_originals']));

        return true;
    }

    /** Same advisory lock the isolation command uses, so a switch never races a running write. MySQL only. */
    private function lock(KonveksiConnection $konveksi, string $lock): bool
    {
        $connection = $konveksi->connection();
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return false;
        }
        if ((int) ($connection->selectOne('SELECT GET_LOCK(?, 30) AS got', [$lock])->got ?? 0) !== 1) {
            throw new \RuntimeException('Tunggu transaksi berjalan sebelum memindahkan koleksi.');
        }

        return true;
    }
}
