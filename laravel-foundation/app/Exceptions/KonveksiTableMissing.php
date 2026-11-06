<?php

namespace App\Exceptions;

use PDOException;

/**
 * A collection is mapped in system_table_map but its table does not exist: a broken installation, reported like any
 * other database failure (masked to the client, traceable by request id) instead of as a business error.
 */
final class KonveksiTableMissing extends PDOException
{
    public static function for(string $table): self
    {
        return new self("Tabel Konveksi {$table} belum ada. Jalankan `php artisan migrate`.");
    }
}
