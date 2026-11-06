<?php

namespace App\Services\Konveksi;

use App\Services\Konveksi\Storage\CollectionDriver;
use App\Services\Konveksi\Typed\TypedCollectionDriver;
use App\Services\Konveksi\Typed\TypedDefinition;

/**
 * Registry of the collections that have a typed table layout (level B), read from database/schema/konveksi_typed.php.
 * A table that is not registered here is a plain JSON collection table.
 */
final class TypedCollections
{
    public const FILE = 'database/schema/konveksi_typed.php';

    /** @var array<string, TypedDefinition>|null */
    private static ?array $definitions = null;

    /** @return array<string, TypedDefinition> collection key => definition */
    public function definitions(): array
    {
        if (self::$definitions === null) {
            self::$definitions = [];
            foreach ((array) require base_path(self::FILE) as $key => $spec) {
                self::$definitions[(string) $key] = TypedDefinition::fromArray((string) $key, $spec);
            }
        }

        return self::$definitions;
    }

    public function definition(string $key): ?TypedDefinition
    {
        return $this->definitions()[$key] ?? null;
    }

    public function driverFor(string $table): ?CollectionDriver
    {
        foreach ($this->definitions() as $definition) {
            if ($definition->table === $table) {
                return new TypedCollectionDriver($definition);
            }
        }

        return null;
    }
}
