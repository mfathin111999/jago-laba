<?php

namespace App\Pilots\Konveksi;

use App\Audit\AuditLogger;
use App\Auth\LegacyUser;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Real Konveksi backups: a gzip JSON snapshot of every mapped table, an optional mysqldump, and a manifest of
 * SHA-256 checksums, all recorded in backup_log with the true size and row count. A backup is only reported
 * as successful after the files exist; a failure is logged as such and never reported as success.
 */
final class KonveksiBackupService
{
    private const TABLE_PATTERN = '/^jl_data_[a-z0-9_]+$/';

    private const DUMP_TIMEOUT_SECONDS = 300;

    private const MIN_DUMP_BYTES = 100;

    private const PENDING_MIN_AGE_SECONDS = 60;

    public function __construct(
        private readonly KonveksiConnection $konveksi,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function create(LegacyUser $user, string $mode = 'MANUAL'): array
    {
        $mode = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', $mode) ?: 'MANUAL');
        $id = 'BKP-'.$mode.'-'.date('Ymd-His');
        $base = 'jago_laba_'.date('Ymd_His');
        $directory = null;

        try {
            $directory = $this->directoryFor($mode);
            $files = [];

            [$snapshotPath, $totalRows, $missing] = $this->writeSnapshot($directory, $base, $id, $mode);
            $files[] = $snapshotPath;
            $sqlPath = $this->writeDump($directory, $base);
            if ($sqlPath !== null) {
                $files[] = $sqlPath;
            }

            $checksums = [];
            foreach ($files as $file) {
                $checksums[basename($file)] = hash_file('sha256', $file);
            }
            $manifest = [
                'backup_id' => $id,
                'created_at' => now()->toIso8601String(),
                'mode' => $mode,
                'status' => 'SUCCESS',
                'database' => $this->konveksi->connection()->getDatabaseName(),
                'total_rows' => $totalRows,
                'snapshot_file' => basename($snapshotPath),
                'sql_file' => $sqlPath !== null ? basename($sqlPath) : null,
                'missing_tables' => $missing,
                'checksums' => $checksums,
            ];
            $manifestPath = $directory.'/'.$base.'_manifest.json';
            $this->put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $files[] = $manifestPath;

            $external = $this->externalCopy($files, $mode);
            $size = (int) array_sum(array_map('filesize', $files));
            $message = ($sqlPath !== null ? 'Backup SQL + JSON selesai' : 'Backup JSON selesai; mysqldump tidak dikonfigurasi atau tidak berhasil.')
                .($missing !== [] ? ' Tabel terpetakan yang belum ada: '.count($missing).'.' : '')
                .(isset($external['error']) ? ' Salinan eksternal gagal.' : '');

            $this->log($id, $mode, 'Berhasil', $sqlPath, $snapshotPath, $manifestPath, $totalRows, $size, hash('sha256', implode('|', $checksums)), $message);
            $this->audit->record($user, 'Konveksi', 'BACKUP_DATABASE', $id, $id, null, $manifest, "Backup database {$mode}");

            return [
                'status' => 'success',
                'id' => $id,
                'backup_id' => $id,
                'mode' => $mode,
                'waktu' => $manifest['created_at'],
                'status_text' => 'Berhasil',
                'status_backup' => 'Berhasil',
                'statusLabel' => 'Berhasil',
                'sql_file' => $sqlPath,
                'snapshot_file' => $snapshotPath,
                'manifest_file' => $manifestPath,
                'external_files' => $external,
                'jumlah_row' => $totalRows,
                'ukuran' => $size,
                'url' => 'file:///'.$directory,
                'message' => 'Backup database lokal berhasil dibuat.',
            ];
        } catch (Throwable $exception) {
            // A partial backup must never be mistaken for a valid one.
            foreach ($directory !== null ? (glob($directory.'/'.$base.'*') ?: []) : [] as $partial) {
                @unlink($partial);
            }
            $this->logFailure($id, $mode, $exception);

            throw new RuntimeException('Backup gagal. Periksa izin folder backup dan ruang disk, lalu coba lagi.', 0, $exception);
        }
    }

    /**
     * Requests a snapshot. Repeated requests collapse into one that the scheduler runs once the marker is old enough.
     *
     * @return array<string, mixed>
     */
    public function scheduleSmart(LegacyUser $user): array
    {
        $marker = $this->markerPath();
        $this->ensureDirectory(dirname($marker));

        // Keep the first request time so a steady stream of requests cannot postpone the snapshot forever.
        if (! is_file($marker)) {
            $this->put($marker, json_encode(['requested_at' => now()->toIso8601String(), 'requested_by' => $user->getUsername() ?: 'system'], JSON_THROW_ON_ERROR));
        }

        return ['status' => 'scheduled', 'mode' => 'MYSQL_LOCAL', 'message' => 'Smart snapshot dijadwalkan. Data transaksi sudah tersimpan di MySQL.'];
    }

    /**
     * Runs the requested snapshot once it is at least a minute old. Returns null when nothing is due.
     *
     * @return array<string, mixed>|null
     */
    public function processPending(): ?array
    {
        $marker = $this->markerPath();
        if (! is_file($marker) || time() - (int) filemtime($marker) < self::PENDING_MIN_AGE_SECONDS) {
            return null;
        }

        @unlink($marker);

        return $this->create(new LegacyUser(['id' => 'SYSTEM', 'username' => 'system', 'role_id' => 'SYSTEM']), 'SMART');
    }

    private function markerPath(): string
    {
        return storage_path('app/private/pending_snapshot.json');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(): array
    {
        $connection = $this->konveksi->connection();
        if (! $connection->getSchemaBuilder()->hasTable('backup_log')) {
            return [];
        }

        return $connection->table('backup_log')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->backup_id,
                'backup_id' => $row->backup_id,
                'waktu' => $row->created_at,
                'mode' => $row->mode,
                'kategori' => $row->mode,
                'ukuran' => $row->size_bytes,
                'status' => $row->status,
                'url' => $row->snapshot_file ?: $row->sql_file,
                'keterangan' => $row->message,
            ])
            ->all();
    }

    /**
     * Writes every mapped table as {"metadata": {...}, "tables": {"<sheet>": [rows...]}} (gzip when available).
     * Reads happen in one transaction so the snapshot is consistent across tables.
     *
     * @return array{0: string, 1: int, 2: list<string>} path, total rows, mapped tables that do not exist
     */
    private function writeSnapshot(string $directory, string $base, string $id, string $mode): array
    {
        $connection = $this->konveksi->connection();
        $gzip = function_exists('gzopen');
        $path = $directory.'/'.$base.'.json'.($gzip ? '.gz' : '');
        $handle = $gzip ? gzopen($path, 'wb9') : fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Snapshot tidak dapat ditulis.');
        }
        $write = fn (string $chunk) => $gzip ? gzwrite($handle, $chunk) : fwrite($handle, $chunk);

        $metadata = ['backup_id' => $id, 'created_at' => now()->toIso8601String(), 'mode' => $mode, 'database' => $connection->getDatabaseName()];
        $total = 0;
        $missing = [];

        try {
            $write('{"metadata":'.json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).',"tables":{');
            $connection->transaction(function () use ($connection, $write, &$total, &$missing): void {
                $first = true;
                foreach ($connection->table('system_table_map')->orderBy('id')->get(['sheet_name', 'table_name']) as $map) {
                    $table = (string) $map->table_name;
                    if (preg_match(self::TABLE_PATTERN, $table) !== 1) {
                        throw new RuntimeException('Pemetaan tabel Konveksi tidak valid.');
                    }
                    if (! $connection->getSchemaBuilder()->hasTable($table)) {
                        $missing[] = $table;

                        continue;
                    }

                    $rows = $connection->table($table)->orderBy('_row_order')->orderBy('_row_id')->pluck('_data_json')
                        ->map(static fn ($json) => json_decode((string) $json, true))->filter(static fn ($row) => is_array($row))->values()->all();
                    $total += count($rows);
                    $write(($first ? '' : ',').json_encode((string) $map->sheet_name, JSON_UNESCAPED_UNICODE).':'.json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                    $first = false;
                }
            });
            $write('}}');
        } finally {
            $gzip ? gzclose($handle) : fclose($handle);
        }

        if (! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('Snapshot kosong.');
        }

        return [$path, $total, $missing];
    }

    /** Runs mysqldump when configured. The password travels in the environment, never on the command line. */
    private function writeDump(string $directory, string $base): ?string
    {
        $binary = (string) config('jago.backup.mysqldump', '');
        if ($binary === '' || ! is_file($binary)) {
            return null;
        }
        $config = $this->konveksi->connection()->getConfig();
        if (! in_array($config['driver'] ?? '', ['mysql', 'mariadb'], true)) {
            return null;
        }

        $path = $directory.'/'.$base.'.sql';
        $process = new Process([
            $binary, '--host='.($config['host'] ?? '127.0.0.1'), '--port='.(int) ($config['port'] ?? 3306),
            '--user='.($config['username'] ?? ''), '--single-transaction', '--default-character-set=utf8mb4',
            '--routines', '--triggers', '--result-file='.$path, (string) ($config['database'] ?? ''),
        ], null, ['MYSQL_PWD' => (string) ($config['password'] ?? '')], null, self::DUMP_TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (Throwable) {
            @unlink($path);

            return null;
        }
        if (! $process->isSuccessful() || ! is_file($path) || filesize($path) < self::MIN_DUMP_BYTES) {
            @unlink($path);

            return null;
        }

        return $path;
    }

    /**
     * @param  list<string>  $files
     * @return array<int|string, string>
     */
    private function externalCopy(array $files, string $mode): array
    {
        $external = trim((string) config('jago.backup.external_directory', ''));
        if ($external === '') {
            return [];
        }

        try {
            $directory = rtrim($external, '/\\').'/'.$mode.'/'.date('Y').'/'.date('m');
            $this->ensureDirectory($directory);
            $copied = [];
            foreach ($files as $file) {
                if (@copy($file, $directory.'/'.basename($file))) {
                    $copied[] = $directory.'/'.basename($file);
                }
            }

            return $copied;
        } catch (Throwable $exception) {
            return ['error' => $exception->getMessage()];
        }
    }

    private function directoryFor(string $mode): string
    {
        $root = (string) (config('jago.backup.local_directory') ?: storage_path('app/private/backups/konveksi'));
        $directory = rtrim($root, '/\\').'/'.$mode.'/'.date('Y').'/'.date('m');
        $this->ensureDirectory($directory);

        return $directory;
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Gagal membuat folder backup.');
        }
    }

    private function put(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Berkas backup tidak dapat ditulis.');
        }
    }

    private function log(string $id, string $mode, string $status, ?string $sql, ?string $snapshot, ?string $manifest, int $rows, int $size, string $checksum, string $message): void
    {
        $connection = $this->konveksi->connection();
        if (! $connection->getSchemaBuilder()->hasTable('backup_log')) {
            throw new RuntimeException('Tabel backup_log belum ada; backup tidak dapat dicatat.');
        }

        $connection->table('backup_log')->insert([
            'backup_id' => $id, 'mode' => $mode, 'status' => $status, 'sql_file' => $sql, 'snapshot_file' => $snapshot,
            'manifest_file' => $manifest, 'total_rows' => $rows, 'size_bytes' => $size, 'checksum' => $checksum,
            'message' => $message, 'created_at' => now(),
        ]);
    }

    /** Records a failed attempt without masking the original error. */
    private function logFailure(string $id, string $mode, Throwable $exception): void
    {
        try {
            $this->log($id, $mode, 'Gagal', null, null, null, 0, 0, hash('sha256', $id), 'Backup gagal: '.substr($exception->getMessage(), 0, 200));
        } catch (Throwable) {
        }
    }
}
