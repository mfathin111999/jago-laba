<?php

namespace Tests\Unit;

use ReflectionClass;
use Tests\TestCase;

/**
 * Every marketplace service must be buildable by the container. A service that is still constructed by hand with
 * the wrong argument (for example a raw PDO instead of an injected service) only fails when that code path runs, which
 * a SQLite test often never reaches; this catches it up front.
 */
final class ServicesResolveTest extends TestCase
{
    /** @return list<class-string> */
    private function classes(): array
    {
        $found = [];
        foreach (['Services/Marketplace', 'Marketplace'] as $folder) {
            $base = dirname(__DIR__, 2).'/app/'.$folder;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
                $relative = substr($path, strlen(str_replace(DIRECTORY_SEPARATOR, '/', dirname(__DIR__, 2).'/app/')), -4);
                $found[] = 'App'.chr(92).str_replace('/', chr(92), $relative);
            }
        }

        return $found;
    }

    public function test_every_marketplace_service_can_be_built_by_the_container(): void
    {
        $built = 0;
        foreach ($this->classes() as $class) {
            if (! class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isEnum() || ! $reflection->isInstantiable()) {
                continue;
            }
            $constructor = $reflection->getConstructor();
            $needsValues = false;
            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();
                if (! $parameter->isOptional() && (! $type instanceof \ReflectionNamedType || $type->isBuiltin())) {
                    $needsValues = true;
                }
            }
            if ($needsValues) {
                continue; // value objects and clients that take their settings as arguments
            }
            $this->assertIsObject(app()->make($class), $class);
            $built++;
        }

        $this->assertGreaterThan(30, $built, 'the scan should have found the services');
    }

    public function test_services_are_never_constructed_by_hand_with_a_raw_pdo(): void
    {
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php' && preg_match('/new\s+\w+\(\s*\$this->pdo\(\)\s*[,)]/', (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_services_talk_to_the_database_through_laravels_connection_not_through_raw_pdo(): void
    {
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php' && preg_match('/\bPDO::|->prepare\(\s*["\']|new PDO\b/', (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders);
    }
}
