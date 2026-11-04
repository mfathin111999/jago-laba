<?php

namespace App\Marketplace;

/**
 * Prepares the process for the legacy marketplace services that Laravel still runs (sync engine, clients, vault):
 * they read configuration from the process environment and from a static Database class.
 */
final class LegacyRuntime
{
    private static bool $prepared = false;

    public static function prepare(): void
    {
        if (self::$prepared) {
            return;
        }
        // Laravel loads .env on its own; the legacy code reads getenv(), so export the JL_* settings once.
        foreach (array_merge($_ENV, $_SERVER) as $name => $value) {
            if (is_string($name) && str_starts_with($name, 'JL_') && is_string($value) && $value !== '' && getenv($name) === false) {
                putenv($name.'='.$value);
            }
        }
        $root = dirname(__DIR__, 3);
        if (! class_exists(\Database::class, false)) {
            require_once $root.'/app/Core/Database/Database.php';
        }
        // Same loading order as the legacy bootstrap, without its session handling (Laravel owns the session).
        require_once $root.'/app/Bootstrap/SharedRuntime.php';
        \SharedRuntime::boot();
        require_once $root.'/app/Modules/Marketplace/Module.php';
        \MarketplaceModule::boot();
        self::$prepared = true;
    }
}
