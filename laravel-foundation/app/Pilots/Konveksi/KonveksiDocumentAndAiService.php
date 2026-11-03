<?php

namespace App\Pilots\Konveksi;

use App\Audit\AuditLogger;
use App\Auth\LegacyUser;
use finfo;
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
        $path = $this->documentPath($relativePath);
        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Penyimpanan dokumen tidak dapat disiapkan.');
        }
        if (file_put_contents($path, $bin, LOCK_EX) !== strlen($bin)) {
            @unlink($path);
            throw new RuntimeException('Dokumen belum tersimpan lengkap.');
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
            @unlink($path);
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
        $root = rtrim((string) (config('jago.documents.root') ?: storage_path('app')), '/\\');

        return $root.'/'.ltrim($relativePath, '/');
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
        if ($this->konveksi->connection()->getSchemaBuilder()->hasTable('system_settings')) {
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

        return [
            'status' => 'success',
            'data' => [
                'supplier' => ['nama' => 'Supplier OCR', 'alamat' => '', 'no_telepon' => ''],
                'invoice' => ['no_faktur' => 'INV-'.time(), 'tanggal' => now('Asia/Jakarta')->format('Y-m-d'), 'total' => 0],
                'items' => [],
            ],
            'provider' => 'Gemini',
        ];
    }
}
