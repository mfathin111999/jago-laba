<?php

namespace App\Services\Marketplace;

use App\Auth\LegacyUser;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Per-company marketplace logo and display preferences. Local database only.
 */
final class MarketplaceBrandingService
{
    public const MAX_BYTES = 2097152;

    private const MAX_PIXELS = 2048;

    private const MIMES = ['image/png', 'image/jpeg', 'image/webp'];

    private const SIZES = [28, 32, 36, 44, 52];

    private const FITS = ['contain', 'cover'];

    public function __construct(private readonly DatabaseManager $database) {}

    /**
     * @return array<string, mixed>
     */
    public function read(string $companyKey, bool $withImage = false): array
    {
        $default = ['logo_mime' => null, 'logo_size' => 32, 'logo_fit' => 'contain', 'logo_hash' => null, 'updated_at' => null];
        if (! Schema::hasTable('marketplace_branding')) {
            return $default;
        }

        $columns = $withImage ? ['*'] : ['company_key', 'logo_mime', 'logo_size', 'logo_fit', 'logo_hash', 'updated_at'];
        $row = $this->database->connection()->table('marketplace_branding')->where('company_key', $companyKey)->first($columns);
        if ($row === null) {
            return $default;
        }

        $branding = (array) $row;
        $branding['logo_size'] = $this->logoSize((int) $branding['logo_size']);

        return $branding;
    }

    /**
     * @return array<string, mixed>
     */
    public function save(LegacyUser $user, string $companyKey, ?string $bytes, int $size, string $fit, bool $reset = false): array
    {
        if (! in_array($size, self::SIZES, true) || ! in_array($fit, self::FITS, true)) {
            throw new InvalidArgumentException('Pilihan tampilan logo tidak valid.');
        }

        $current = $this->read($companyKey, true);
        $mime = $bytes !== null ? $this->validateImage($bytes) : ($current['logo_mime'] ?? null);
        $image = $bytes ?? ($current['logo_blob'] ?? null);
        if ($reset) {
            $image = null;
            $mime = null;
        }

        $values = [
            'logo_blob' => $image,
            'logo_mime' => $mime,
            'logo_size' => $this->logoSize($size),
            'logo_fit' => $fit,
            'logo_hash' => $image !== null ? hash('sha256', $image) : null,
            'updated_by' => $user->getLegacyUserId(),
            'updated_at' => now(),
        ];

        $connection = $this->database->connection();
        $connection->transaction(function () use ($connection, $companyKey, $values): void {
            $existing = $connection->table('marketplace_branding')->where('company_key', $companyKey)->lockForUpdate()->exists();
            if ($existing) {
                $connection->table('marketplace_branding')->where('company_key', $companyKey)->update($values);
            } else {
                $connection->table('marketplace_branding')->insert(['company_key' => $companyKey] + $values);
            }
        });

        return $this->read($companyKey);
    }

    public function validateImage(string $bytes): string
    {
        if (strlen($bytes) > self::MAX_BYTES || strlen($bytes) < 16) {
            throw new InvalidArgumentException('Pilih gambar PNG, JPG, atau WebP maksimal 2 MB.');
        }

        $image = @getimagesizefromstring($bytes);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! $image || ! in_array($mime, self::MIMES, true) || ($image['mime'] ?? '') !== $mime) {
            throw new InvalidArgumentException('Format logo harus PNG, JPG, atau WebP yang valid.');
        }
        if ($image[0] > self::MAX_PIXELS || $image[1] > self::MAX_PIXELS || $image[0] < 1 || $image[1] < 1) {
            throw new InvalidArgumentException('Ukuran logo maksimal 2048 × 2048 piksel.');
        }

        return $mime;
    }

    public function logoSize(int $size): int
    {
        return match ($size) {
            28 => 28,
            32, 44 => 32,
            36, 52 => 36,
            default => 32,
        };
    }
}
