<?php

namespace App\Services\Konveksi;

use App\Services\Konveksi\Storage\CollectionDriver;

/**
 * Registry of the collections that have a typed table layout (level B). A table that is not registered here is a
 * plain JSON collection table.
 */
final class TypedCollections
{
    /** @return array<string, class-string<CollectionDriver>> typed table name => driver */
    public function drivers(): array
    {
        return [];
    }

    public function driverFor(string $table): ?CollectionDriver
    {
        $class = $this->drivers()[$table] ?? null;

        return $class === null ? null : new $class;
    }
}
