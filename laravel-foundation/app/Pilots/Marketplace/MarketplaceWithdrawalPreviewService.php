<?php

namespace App\Pilots\Marketplace;

use App\Auth\LegacyUser;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

$legacyRoot = dirname(__DIR__, 4);
require_once $legacyRoot.'/app/Modules/Marketplace/Services/MarketplaceWithdrawalService.php';
require_once $legacyRoot.'/app/Modules/Marketplace/Services/MarketplaceWithdrawalXlsx.php';

/**
 * Read-only Laravel boundary for the legacy withdrawal import preview.
 *
 * The legacy parser remains the source of truth for dates, currency, amount
 * precision, masking, and duplicate detection within one upload. This
 * adapter deliberately stops before MarketplaceWithdrawalService::import().
 */
final class MarketplaceWithdrawalPreviewService
{
    public const MAX_UPLOAD_BYTES = 10485760;

    public function __construct(private readonly DatabaseManager $database) {}

    /**
     * Returns a 10-row display sample in 'rows' and every parsed row in 'staged_rows' (kept server-side for confirmation).
     *
     * @return array{key: string, count: int, rows: list<array<string, mixed>>, staged_rows: list<array<string, mixed>>, meta: array<string, mixed>, hash: string, expires_at: int}
     */
    public function preview(LegacyUser $user, string $companyKey, string $storeId, UploadedFile $file): array
    {
        $store = $this->database->connection()->table('marketplace_stores')
            ->where('id', $storeId)
            ->where('company_key', $companyKey)
            ->whereNull('archived_at')
            ->first();

        if ($store === null) {
            throw new DomainException('Toko marketplace tidak ditemukan dalam perusahaan aktif.');
        }

        if (! $file->isValid() || $file->getError() !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Unggah XLSX asli toko maksimal 10 MB atau CSV sesuai template.');
        }

        $size = (int) ($file->getSize() ?: 0);
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            throw new InvalidArgumentException('Unggah XLSX asli toko maksimal 10 MB atau CSV sesuai template.');
        }

        $path = $file->getRealPath();
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw new InvalidArgumentException('Berkas upload tidak tersedia untuk diproses.');
        }

        $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        if (! in_array($extension, ['csv', 'xlsx'], true)) {
            throw new InvalidArgumentException('Unggah XLSX asli toko maksimal 10 MB atau CSV sesuai template.');
        }

        $meta = [];
        if ($extension === 'xlsx') {
            if ((string) ($store->platform ?? '') !== 'TikTok Shop') {
                throw new InvalidArgumentException('Format XLSX income ini khusus laporan TikTok Shop. Pilih toko TikTok yang sesuai.');
            }

            $parsed = \MarketplaceWithdrawalXlsx::parse($path);
            $rows = $parsed['rows'];
            $meta = $parsed['meta'];
        } else {
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new InvalidArgumentException('Berkas CSV tidak dapat dibaca.');
            }

            $rows = \MarketplaceWithdrawalService::parseCsv($contents);
        }

        $hash = hash_file('sha256', $path);
        if (! is_string($hash) || $hash === '') {
            throw new InvalidArgumentException('Checksum berkas tidak dapat dihitung.');
        }

        $expiresAt = time() + 900;

        return [
            'key' => bin2hex(random_bytes(24)),
            'count' => count($rows),
            'rows' => array_slice($rows, 0, 10),
            'staged_rows' => $rows,
            'meta' => $meta,
            'hash' => $hash,
            'expires_at' => $expiresAt,
        ];
    }

    public function actorKey(LegacyUser $user): string
    {
        return $user->getSource() === 'auth_users_v2'
            ? 'AUTHV2-'.$user->getLegacyUserId()
            : $user->getLegacyUserId();
    }
}
