<?php

namespace Tests\Feature;

use App\Services\Konveksi\KonveksiBackupService;
use App\Services\Konveksi\KonveksiConnection;
use App\Services\Konveksi\KonveksiDocumentAndAiService;
use App\Services\Konveksi\KonveksiHutangService;
use App\Services\Konveksi\KonveksiInventoryService;
use App\Services\Konveksi\KonveksiPilotReadService;
use App\Services\Konveksi\KonveksiPurchaseWriteService;
use App\Services\Konveksi\KonveksiSalesWriteService;
use App\Services\Konveksi\KonveksiSyncAndHppService;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

final class KonveksiConnectionTest extends TestCase
{
    private ?string $configPath = null;

    protected function tearDown(): void
    {
        if ($this->configPath !== null) {
            @unlink($this->configPath);
        }
        parent::tearDown();
    }

    private function isolatedConfig(bool $isolated = true): void
    {
        $this->configPath = tempnam(sys_get_temp_dir(), 'kvx');
        file_put_contents($this->configPath, '<?php return '.var_export([
            'isolated' => $isolated, 'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
            'name' => 'konveksi_staging', 'user' => 'u', 'pass' => 'p', 'company_key' => 'A',
        ], true).';');
        config(['jago.konveksi_database_isolated' => true, 'jago.konveksi_database_config_path' => $this->configPath]);
        $this->app->detectEnvironment(fn () => 'staging');
    }

    public function test_default_connection_is_used_when_konveksi_is_not_isolated(): void
    {
        $resolver = new KonveksiConnection($this->app['db']);

        $this->assertFalse($resolver->isolated());
        $this->assertSame($this->app['db']->connection(), $resolver->connection());
    }

    public function test_isolated_mode_resolves_the_separate_konveksi_database_once(): void
    {
        $this->isolatedConfig();
        $resolver = new KonveksiConnection($this->app['db']);

        $connection = $resolver->connection();

        $this->assertSame('konveksi_staging', $connection->getDatabaseName());
        $this->assertNotSame($this->app['db']->connection()->getDatabaseName(), $connection->getDatabaseName());
        $this->assertSame($connection, $resolver->connection(), 'the connection is built once and reused');
    }

    public function test_isolated_mode_fails_closed_when_the_config_does_not_mark_it_isolated(): void
    {
        $this->isolatedConfig(false);

        $this->expectException(RuntimeException::class);
        (new KonveksiConnection($this->app['db']))->connection();
    }

    public function test_isolated_mode_fails_closed_when_the_config_file_is_missing(): void
    {
        $this->isolatedConfig();
        config(['jago.konveksi_database_config_path' => sys_get_temp_dir().'/does-not-exist.php']);

        $this->expectException(RuntimeException::class);
        (new KonveksiConnection($this->app['db']))->connection();
    }

    public function test_every_konveksi_service_reads_and_writes_through_the_same_resolver(): void
    {
        $resolver = $this->app->make(KonveksiConnection::class);

        foreach ([
            KonveksiPilotReadService::class, KonveksiBackupService::class, KonveksiDocumentAndAiService::class,
            KonveksiHutangService::class, KonveksiInventoryService::class, KonveksiPurchaseWriteService::class,
            KonveksiSalesWriteService::class, KonveksiSyncAndHppService::class,
        ] as $service) {
            $property = new ReflectionProperty($service, 'konveksi');

            $this->assertSame($resolver, $property->getValue($this->app->make($service)), $service.' must use the shared Konveksi connection');
        }
    }
}
