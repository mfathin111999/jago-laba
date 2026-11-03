<?php

namespace App\Http\Controllers;

use App\Pilots\Konveksi\KonveksiConnection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Owner-only operator diagnostics (/api/diagnostics.php and /api/auth-diagnostic.php).
 * Output holds counts and booleans only: no passwords, hashes, absolute paths or exception text.
 */
final class DiagnosticsController
{
    private const EXTENSIONS = ['pdo_mysql', 'curl', 'zlib', 'json', 'fileinfo', 'mbstring', 'openssl'];

    public function __construct(
        private readonly KonveksiConnection $konveksi,
        private readonly DatabaseManager $database,
    ) {}

    public function health(): JsonResponse
    {
        $out = ['status' => 'PASS', 'time' => now()->toIso8601String(), 'checks' => []];

        try {
            $connection = $this->konveksi->connection();
            $out['checks']['database'] = ['status' => 'PASS', 'name' => $connection->getDatabaseName()];
            $out['checks']['migration'] = $this->migration();
            $out['checks']['backup'] = $this->backup();
            $out['checks']['extensions'] = array_combine(self::EXTENSIONS, array_map('extension_loaded', self::EXTENSIONS));

            if ($out['checks']['migration']['status'] === 'FAIL') {
                $out['status'] = 'FAIL';
            }
        } catch (Throwable $exception) {
            Log::error('Diagnostics failed.', ['exception' => $exception::class, 'message' => $exception->getMessage()]);

            return $this->json(['status' => 'FAIL', 'message' => 'Diagnostik tidak dapat dijalankan. Periksa log server.'], 500);
        }

        return $this->json($out);
    }

    public function auth(Request $request): JsonResponse
    {
        $username = trim((string) $request->query('username', 'owner')) ?: 'owner';

        try {
            $rows = $this->database->connection()->table('auth_users_v2')
                ->whereRaw('LOWER(TRIM(username)) = ?', [strtolower($username)])
                ->orderBy('id')->get(['id', 'username', 'name', 'status', 'role_id', 'password_hash']);
        } catch (Throwable $exception) {
            Log::error('Auth diagnostics failed.', ['exception' => $exception::class, 'message' => $exception->getMessage()]);

            return $this->json(['status' => 'error', 'message' => 'Diagnostik login tidak dapat dijalankan.'], 500);
        }

        return $this->json(['status' => 'ok', 'diagnostic' => $rows->map(static fn ($row): array => [
            'id' => $row->id, 'username' => $row->username, 'name' => $row->name, 'status' => $row->status, 'role_id' => $row->role_id,
            'hash_valid' => ! empty(password_get_info((string) $row->password_hash)['algo']),
        ])->all()]);
    }

    /** @return array<string, mixed> */
    private function migration(): array
    {
        $connection = $this->konveksi->connection();
        if (! $connection->getSchemaBuilder()->hasTable('system_table_map')) {
            return ['status' => 'FAIL', 'tables' => 0, 'failed' => 0, 'rows' => [], 'message' => 'system_table_map tidak ada.'];
        }

        $rows = [];
        $failed = 0;
        foreach ($connection->table('system_table_map')->orderBy('id')->get(['sheet_name', 'table_name', 'source_row_count']) as $map) {
            $table = (string) $map->table_name;
            $valid = preg_match('/^jl_data_[a-z0-9_]+$/', $table) === 1 && $connection->getSchemaBuilder()->hasTable($table);
            $actual = $valid ? $connection->table($table)->count() : null;
            $ok = $valid && $actual === (int) $map->source_row_count;
            $failed += $ok ? 0 : 1;
            $rows[] = ['sheet' => $map->sheet_name, 'table' => $table, 'source' => (int) $map->source_row_count, 'actual' => $actual, 'status' => $ok ? 'PASS' : 'FAIL'];
        }

        return ['status' => $failed > 0 ? 'FAIL' : 'PASS', 'tables' => count($rows), 'failed' => $failed, 'rows' => $rows];
    }

    /** @return array<string, mixed> */
    private function backup(): array
    {
        $directory = (string) (config('jago.backup.local_directory') ?: storage_path('app/private/backups/konveksi'));
        $dump = (string) config('jago.backup.mysqldump', '');

        return [
            'status' => is_dir($directory) && is_writable($directory) ? 'PASS' : 'WARNING',
            'directory_writable' => is_dir($directory) && is_writable($directory),
            'external_directory_configured' => (string) config('jago.backup.external_directory', '') !== '',
            'mysqldump_configured' => $dump !== '',
            'mysqldump_exists' => $dump !== '' && is_file($dump),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Cache-Control', 'no-store, private');
    }
}
