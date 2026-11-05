<?php

namespace App\Services\Konveksi;

use App\Audit\AuditLogger;
use App\Auth\LegacyUser;
use finfo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

final class KonveksiDocumentAndAiService
{
    public function __construct(
        private readonly KonveksiConnection $konveksi,
        private readonly AuditLogger $audit,
    ) {}

    public function saveBase64Document(LegacyUser $user, string $companyKey, string $base64, string $fileName, string $type = 'General', string $referenceId = ''): array
    {
        if (strlen($base64) > 15000000) {
            throw new InvalidArgumentException('Dokumen maksimal 10 MB.');
        }

        $type = (string) preg_replace('/[^A-Za-z0-9_-]+/', '_', trim($type) ?: 'General');
        $safe = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($fileName ?: 'document.pdf'));

        $raw = (string) preg_replace('#^data:[^;]+;base64,#', '', $base64);
        $bin = base64_decode($raw, true);
        if ($bin === false || $bin === '' || strlen($bin) > 10485760) {
            throw new InvalidArgumentException('Data dokumen tidak valid atau melebihi 10 MB.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($bin);
        $allowed = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if (! isset($allowed[$detected])) {
            throw new InvalidArgumentException('Dokumen harus PDF, JPG, PNG, atau WebP yang valid.');
        }

        $fid = 'DOC-'.bin2hex(random_bytes(16));
        $connection = $this->konveksi->connection();
        if (! $connection->getSchemaBuilder()->hasTable('document_files')) {
            throw new RuntimeException('Registri dokumen belum tersedia; dokumen tidak disimpan.');
        }

        $relativePath = "private/konveksi/documents/{$type}/".date('Y/m')."/{$fid}.{$allowed[$detected]}";
        $disk = $this->documentDisk();
        // put() creates the missing directories (private, 0700) and writes under an exclusive lock.
        if (! $disk->put($relativePath, $bin)) {
            $disk->delete($relativePath);

            throw new RuntimeException(is_dir(dirname($this->documentPath($relativePath))) ? 'Dokumen belum tersimpan lengkap.' : 'Penyimpanan dokumen tidak dapat disiapkan.');
        }

        try {
            $connection->table('document_files')->insert([
                'file_id' => $fid,
                'document_type' => $type,
                'reference_id' => $referenceId,
                'file_name' => $safe,
                'relative_path' => $relativePath,
                'mime_type' => $detected,
                'size_bytes' => strlen($bin),
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $disk->delete($relativePath);
            throw $exception;
        }

        $url = 'api/konveksi-document.php?id='.rawurlencode($fid);

        $this->audit->record(
            $user,
            'Konveksi',
            'SAVE_DOCUMENT',
            $fid,
            $referenceId,
            null,
            ['file_id' => $fid, 'file_name' => $safe, 'type' => $type],
            "Simpan dokumen {$safe} ({$type})",
        );

        return [
            'status' => 'success',
            'file_id' => $fid,
            'file_name' => $safe,
            'url' => $url,
            'preview_url' => $url,
            'drive_url' => $url,
            'folder_name' => $type,
            'sharing_status' => 'PRIVATE_AUTHENTICATED',
            'document_type' => $type,
            'reference_id' => $referenceId,
            'created_at' => now()->toIso8601String(),
            'message' => 'Dokumen tersimpan privat di Jago Laba.',
        ];
    }

    /**
     * Finds a registered document and its file, only if the file really lives inside the private Konveksi
     * document directory. Returns null when it is unknown or the file is missing or outside that directory.
     *
     * @return array{file: object, path: string}|null
     */
    public function locate(string $fileId): ?array
    {
        if (preg_match('/^DOC-[a-f0-9]{32}$/', $fileId) !== 1) {
            throw new InvalidArgumentException('Dokumen tidak valid.');
        }

        $connection = $this->konveksi->connection();
        if (! $connection->getSchemaBuilder()->hasTable('document_files')) {
            return null;
        }
        $file = $connection->table('document_files')->where('file_id', $fileId)->first();
        if ($file === null) {
            return null;
        }

        $root = realpath($this->documentPath('private/konveksi'));
        $path = realpath($this->documentPath((string) $file->relative_path));
        if ($root === false || $path === false || ! is_file($path)) {
            return null;
        }
        $normalise = static fn (string $value): string => strtolower(str_replace('\\', '/', $value));
        if (! str_starts_with($normalise($path), $normalise($root).'/')) {
            return null;
        }

        return ['file' => $file, 'path' => $path];
    }

    /** Absolute path of a stored document. The root is private server storage, never the web root. */
    public function documentPath(string $relativePath): string
    {
        return $this->documentRoot().'/'.ltrim($relativePath, '/');
    }

    private function documentRoot(): string
    {
        return rtrim((string) (config('jago.documents.root') ?: storage_path('app')), '/\\');
    }

    /**
     * A local disk on the private document root. Built per call from config('jago.documents.root') (the legacy storage
     * root, so existing files stay reachable); never the web root.
     */
    private function documentDisk(): Filesystem
    {
        return Storage::build([
            'driver' => 'local',
            'root' => $this->documentRoot(),
            'lock' => LOCK_EX,
            'throw' => false,
            'permissions' => ['dir' => ['public' => 0700, 'private' => 0700]],
        ]);
    }

    public function saveTemplate(LegacyUser $user, string $companyKey, array $p): array
    {
        $id = (string) ($p['id'] ?? ($p['id_template'] ?? 'TPL-DEFAULT'));
        $connection = $this->konveksi->connection();
        $mapping = $connection->table('system_table_map')->where('api_key', 'template_dokumen')->first();

        if (! $mapping) {
            return ['status' => 'success', 'message' => 'Template dokumen tersimpan.', 'data' => $p];
        }

        $table = (string) $mapping->table_name;
        $existingRows = $connection->table($table)->get();
        $foundRowId = null;

        foreach ($existingRows as $row) {
            $dec = json_decode((string) $row->_data_json, true);
            if (is_array($dec) && ((string) ($dec['id'] ?? ($dec['id_template'] ?? '')) === $id)) {
                $foundRowId = $row->_row_id;
                $p = array_merge($dec, $p, ['updated_at' => now()->toIso8601String()]);
                break;
            }
        }

        if ($foundRowId !== null) {
            $connection->table($table)->where('_row_id', $foundRowId)->update([
                '_data_json' => json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } else {
            $p = array_merge($p, ['id' => $id, 'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String()]);
            $connection->table($table)->insert([
                '_row_order' => 0,
                '_data_json' => json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }

        $this->audit->record(
            $user,
            'Konveksi',
            'SAVE_DOCUMENT_TEMPLATE',
            $id,
            $id,
            null,
            $p,
            "Simpan template dokumen {$id}",
        );

        return ['status' => 'success', 'message' => 'Template dokumen tersimpan.', 'data' => $p];
    }

    public function geminiStatus(): array
    {
        $connection = $this->konveksi->connection();
        $v = '';
        if ($this->konveksi->connection()->getSchemaBuilder()->hasTable('system_settings')) {
            $v = (string) ($connection->table('system_settings')->where('setting_key', 'GEMINI_API_KEY')->value('setting_value') ?? '');
        }

        $masked = $v !== '' ? substr($v, 0, 4).'••••'.substr($v, -4) : '';

        return [
            'status' => 'success',
            'hasKey' => $v !== '',
            'configured' => $v !== '',
            'maskedKey' => $masked,
            'masked' => $masked,
        ];
    }

    public function saveGeminiKey(LegacyUser $user, string $key): array
    {
        $key = trim($key);
        if ($key === '') {
            throw new RuntimeException('API key kosong.');
        }

        $connection = $this->konveksi->connection();
        if (! $connection->getSchemaBuilder()->hasTable('system_settings')) {
            throw new RuntimeException('Tabel pengaturan sistem belum tersedia; API key tidak tersimpan.');
        }

        $existing = $connection->table('system_settings')->where('setting_key', 'GEMINI_API_KEY')->first();
        if ($existing) {
            $connection->table('system_settings')->where('setting_key', 'GEMINI_API_KEY')->update([
                'setting_value' => $key,
                'updated_at' => now(),
            ]);
        } else {
            $connection->table('system_settings')->insert([
                'setting_key' => 'GEMINI_API_KEY',
                'setting_value' => $key,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->audit->record(
            $user,
            'Konveksi',
            'SAVE_GEMINI_API_KEY',
            'GEMINI_KEY',
            'SETTINGS',
            null,
            ['configured' => true],
            'Simpan kredensial Google Gemini API Key',
        );

        return ['status' => 'success', 'message' => 'Gemini API key tersimpan di server lokal.'];
    }

    public function extractInvoice(array $p): array
    {
        $connection = $this->konveksi->connection();
        $key = '';
        if ($this->konveksi->connection()->getSchemaBuilder()->hasTable('system_settings')) {
            $key = (string) ($connection->table('system_settings')->where('setting_key', 'GEMINI_API_KEY')->value('setting_value') ?? '');
        }

        if ($key === '') {
            return [
                'status' => 'error',
                'code' => 'GEMINI_KEY_MISSING',
                'message' => 'Gemini API key belum disimpan. Gunakan Input Manual atau isi API key pada Pengaturan.',
            ];
        }

        // Reading an invoice sends the image to Google Gemini. That outbound call stays disabled, and nothing is
        // invented in its place: the screen must fall back to manual entry.
        return [
            'status' => 'error',
            'code' => 'PROVIDER_CONNECTOR_INACTIVE',
            'message' => 'Pembacaan faktur otomatis (OCR) belum aktif. Gunakan Input Manual.',
        ];
    }
}
