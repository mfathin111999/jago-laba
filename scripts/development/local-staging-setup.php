<?php

declare(strict_types=1);

/**
 * Builds a DISPOSABLE local staging database with the legacy tooling: core schema, the Konveksi snapshot, every
 * Marketplace migration and an owner login. It refuses to touch anything except a database whose name starts with
 * "jl_staging" on a loopback host.
 *
 *   JL_DB_HOST=127.0.0.1 JL_DB_PORT=3320 JL_DB_NAME=jl_staging_a JL_DB_USER=root JL_DB_PASS= \
 *   php scripts/development/local-staging-setup.php [--owner-password=...]
 */
$root = dirname(__DIR__, 2);
$name = getenv('JL_DB_NAME') ?: '';
$host = getenv('JL_DB_HOST') ?: '';
if (! preg_match('/^jl_staging[a-z0-9_]*$/', $name) || ! in_array($host, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Refusing: JL_DB_NAME must start with jl_staging and JL_DB_HOST must be loopback.\n");
    exit(1);
}
$ownerPassword = 'owner123';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--owner-password=')) {
        $ownerPassword = substr($arg, strlen('--owner-password='));
    }
}

require $root.'/app/Bootstrap/bootstrap.php';

SchemaManager::createDatabase();
$pdo = Database::pdo();
SchemaManager::ensureSystemSchema($pdo);
echo "core schema ready\n";

$import = (new SnapshotImporter($pdo))->importFile($root.'/database/legacy/current_database_snapshot.json', true);
echo 'konveksi snapshot imported: '.json_encode($import)."\n";

// Migrations depend on each other only implicitly, so repeat until a full pass makes no progress.
$files = array_merge(
    glob($root.'/database/migrations/marketplace/migration_*.php') ?: [],
    glob($root.'/database/migrations/marketplace/migration_*.sql') ?: [],
    glob($root.'/database/migrations/shared/migration_*.sql') ?: [],
);
sort($files);
$pending = $files;
for ($pass = 1; $pass <= 6 && $pending !== []; $pass++) {
    $failed = [];
    foreach ($pending as $file) {
        $base = basename($file);
        try {
            if (str_ends_with($file, '.sql')) {
                $sql = (string) file_get_contents($file);
                $pdo->exec($sql);
            } else {
                $code = (string) file_get_contents($file);
                // Migrations are standalone scripts; run each in its own process so exit() and require_once do not leak.
                $output = [];
                $status = 0;
                exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($file).' 2>&1', $output, $status);
                if ($status !== 0) {
                    throw new RuntimeException(trim(implode(' ', array_slice($output, -2))));
                }
            }
            echo "pass $pass ok   $base\n";
        } catch (Throwable $exception) {
            $failed[] = $file;
            echo "pass $pass fail $base: ".substr($exception->getMessage(), 0, 140)."\n";
        }
    }
    if (count($failed) === count($pending)) {
        break;
    }
    $pending = $failed;
}
if ($pending !== []) {
    echo 'WARNING: '.count($pending).' migration(s) never succeeded: '.implode(', ', array_map('basename', $pending))."\n";
}

// The Konveksi write gate refuses to run without its receipts table; production gets it from the legacy installer.
KonveksiModule::boot();
KonveksiOperationGate::install($pdo);
echo "konveksi write-gate schema ready\n";

SchemaManager::ensureOwner($pdo, 'owner', 'Owner Staging', $ownerPassword);
echo "owner login ready (username: owner)\n";
echo 'tables: '.(int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn()."\n";
