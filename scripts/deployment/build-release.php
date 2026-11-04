<?php

declare(strict_types=1);

/**
 * Builds a clean, deployable release directory (the "new artifact") from the working tree:
 *
 *   release/
 *     app/                 legacy modules the Laravel app still loads at runtime (services, views, shared code)
 *     laravel-foundation/  the Laravel app: web root is laravel-foundation/public
 *
 * It never copies: the legacy public/ web root (install.php, repair_owner.php), .env files, tests, node_modules,
 * source maps, logs, backups, or anything under storage/. Production dependencies are installed fresh (no dev packages).
 * It scans the result for secrets and writes RELEASE-MANIFEST.json with a SHA-256 for every file.
 *
 *   php scripts/deployment/build-release.php <output-dir> [--composer=/path/to/composer.phar] [--skip-composer]
 *
 * Run `npm run build` first: the compiled front end (public/build) is part of the release.
 */
$root = dirname(__DIR__, 2);
$out = $argv[1] ?? '';
if ($out === '' || str_starts_with($out, '--')) {
    fwrite(STDERR, "usage: php scripts/deployment/build-release.php <output-dir> [--composer=...] [--skip-composer]\n");
    exit(2);
}
$composer = 'composer';
$skipComposer = false;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--composer=')) {
        $composer = substr($argument, strlen('--composer='));
    }
    $skipComposer = $skipComposer || $argument === '--skip-composer';
}
if (is_dir($out) && count(scandir($out)) > 2) {
    fwrite(STDERR, "Refusing: $out is not empty.\n");
    exit(2);
}
if (! is_file($root.'/laravel-foundation/public/build/manifest.json')) {
    fwrite(STDERR, "Refusing: run `npm run build` in laravel-foundation first (public/build/manifest.json is missing).\n");
    exit(2);
}

/** Paths (relative to the copied tree, forward slashes) that are never shipped. */
$exclude = [
    '#(^|/)\.git(/|$)#', '#(^|/)node_modules(/|$)#', '#(^|/)tests(/|$)#', '#(^|/)\.env(\..*)?$#', '#\.(log|map)$#', '#(^|/)\.phpunit\.(cache|result\.cache)#',
    '#^laravel-foundation/(storage|bootstrap/cache)/#', '#^laravel-foundation/(resources/js|resources/css|docs)/#', '#^laravel-foundation/(vendor|public/hot)(/|$)#',
    '#^laravel-foundation/(package(-lock)?\.json|vite\.config\.js|tsconfig\.json|phpunit\.xml|boost\.json|AGENTS\.md|CLAUDE\.md|design-qa\.md|CHANGELOG\.md|README\.md)$#',
    // The legacy pages are replaced by the Laravel app; only the services and shared code they used to share are still loaded.
    '#^app/Modules/[^/]+/Controllers(/|$)#', '#^app/Modules/Marketplace/Views(/|$)#', '#^app/(Http|views)(/|$)#',
];
$copy = static function (string $from, string $to, string $prefix) use (&$copy, $exclude): int {
    $count = 0;
    foreach (scandir($from) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $relative = ltrim($prefix.'/'.$name, '/');
        foreach ($exclude as $pattern) {
            if (preg_match($pattern, $relative) === 1 || preg_match($pattern, $relative.'/') === 1) {
                continue 2;
            }
        }
        if (is_dir($from.'/'.$name)) {
            @mkdir($to.'/'.$name, 0755, true);
            $count += $copy($from.'/'.$name, $to.'/'.$name, $relative);
        } else {
            copy($from.'/'.$name, $to.'/'.$name);
            $count++;
        }
    }

    return $count;
};

foreach (['', '/app', '/config', '/laravel-foundation'] as $directory) {
    @mkdir($out.$directory, 0755, true);
}
// The legacy services still read their settings from the root config/ (environment driven, no secrets in the files).
$files = $copy($root.'/app', $out.'/app', 'app') + $copy($root.'/config', $out.'/config', 'config') + $copy($root.'/laravel-foundation', $out.'/laravel-foundation', 'laravel-foundation');
foreach (['storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'storage/app/private', 'bootstrap/cache'] as $directory) {
    @mkdir($out.'/laravel-foundation/'.$directory, 0775, true);
    file_put_contents($out.'/laravel-foundation/'.$directory.'/.gitkeep', '');
}
echo "copied $files files\n";

if (! $skipComposer) {
    $command = (str_ends_with($composer, '.phar') ? escapeshellarg(PHP_BINARY).' ' : '').escapeshellarg($composer)
        .' install --no-dev --optimize-autoloader --no-interaction --no-progress --working-dir='.escapeshellarg($out.'/laravel-foundation').' 2>&1';
    passthru($command, $status);
    if ($status !== 0) {
        fwrite(STDERR, "composer install failed\n");
        exit(1);
    }
}

// ----- secret scan (application code and config only; vendor packages are third-party) -----
$patterns = [
    'private key' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
    'AWS access key' => '/\bAKIA[0-9A-Z]{16}\b/',
    'Google API key' => '/\bAIza[0-9A-Za-z_\-]{35}\b/',
    'OpenAI-style key' => '/\bsk-[A-Za-z0-9]{32,}\b/',
    'known default webhook secret' => '/JAGOLABA_MKT_WEBHOOK_SECRET/',
    'literal secret' => '/(secret|password|passwd|token|api[_-]?key|private[_-]?key)[\'"]?\s*(=>|=|:)\s*[\'"][A-Za-z0-9+\/=_\-]{16,}[\'"]/i',
];
$allowlist = [
    // Reviewed: every hit here is a placeholder, a label or a derived value, not a credential.
    '#/\.env\.example$#',
];
$hits = [];
$manifest = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (! $file->isFile()) {
        continue;
    }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($out) + 1));
    $manifest[$relative] = hash_file('sha256', $file->getPathname());
    if (str_starts_with($relative, 'laravel-foundation/vendor/') || preg_match('#\.(png|jpe?g|gif|ico|woff2?|ttf|gz|zip|xlsx)$#i', $relative) === 1) {
        continue;
    }
    foreach ($allowlist as $allowed) {
        if (preg_match($allowed, '/'.$relative) === 1) {
            continue 2;
        }
    }
    $contents = (string) file_get_contents($file->getPathname());
    foreach ($patterns as $label => $pattern) {
        if (preg_match($pattern, $contents, $match) === 1) {
            $hits[] = "$relative: $label (".substr(preg_replace('/\s+/', ' ', $match[0]), 0, 60).')';
        }
    }
}
ksort($manifest);

// ----- things that must NOT be in a release -----
$forbidden = array_values(array_filter(array_keys($manifest), static fn (string $path): bool => preg_match('#(^|/)(install\.php|repair_owner\.php|\.env(\..*)?)$#', $path) === 1 && ! str_ends_with($path, '.env.example')));
$required = ['config/marketplace.php', 'laravel-foundation/artisan', 'laravel-foundation/public/index.php', 'laravel-foundation/public/build/manifest.json', 'laravel-foundation/public/assets/konveksi/js/jl-api.js', 'app/Modules/Konveksi/Views/28_Core.html', 'app/Modules/Marketplace/Services/MarketplaceService.php', 'laravel-foundation/vendor/autoload.php'];
$missing = array_values(array_filter($required, static fn (string $path): bool => ! isset($manifest[$path]) && ! ($skipComposer && str_contains($path, '/vendor/'))));

$commit = trim((string) shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>NUL'));
file_put_contents($out.'/RELEASE-MANIFEST.json', json_encode([
    'built_at' => gmdate('c'), 'git_commit' => $commit !== '' ? $commit : null, 'file_count' => count($manifest), 'web_root' => 'laravel-foundation/public',
    'secret_scan_hits' => $hits, 'files' => $manifest,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

echo count($manifest)." files in the release\n";
foreach ($hits as $hit) {
    echo "SECRET? $hit\n";
}
foreach ($forbidden as $path) {
    echo "FORBIDDEN $path\n";
}
foreach ($missing as $path) {
    echo "MISSING $path\n";
}
exit($hits === [] && $forbidden === [] && $missing === [] ? 0 : 1);
