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
        // The legacy code reads getenv(). Export the JL_* settings collected in config('jago.legacy_env') once
        // (config, not env(): env() returns null outside config files once the configuration is cached).
        // A value already present in the process environment wins.
        self::exportToEnvironment((array) config('jago.legacy_env', []));
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

    /**
     * Makes the given settings visible to getenv() without overriding values the process already has.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function exportToEnvironment(array $settings): void
    {
        foreach ($settings as $name => $value) {
            if (is_string($name) && is_scalar($value) && (string) $value !== '' && getenv($name) === false) {
                putenv($name.'='.$value);
            }
        }
    }
}
