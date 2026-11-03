<?php

namespace App\Pilots\Konveksi;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Resolves the database that holds Konveksi data. Konveksi may live in its own database, separate from the
 * Marketplace/Laravel default; every Konveksi read and write must go through this one resolver so they agree.
 */
final class KonveksiConnection
{
    private ?Connection $connection = null;

    /** @var array<string, mixed>|null */
    private ?array $config = null;

    public function __construct(private readonly DatabaseManager $database) {}

    public function isolated(): bool
    {
        return (bool) config('jago.konveksi_database_isolated', false);
    }

    public function connection(): Connection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        if (! $this->isolated() || app()->environment('testing')) {
            return $this->connection = $this->database->connection();
        }

        $config = $this->config();
        if (! filter_var($config['isolated'] ?? false, FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('Database Konveksi isolated belum diaktifkan secara eksplisit.');
        }

        $driver = strtolower((string) ($config['driver'] ?? 'mysql'));
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Driver database Konveksi tidak didukung.');
        }

        return $this->connection = $this->database->build([
            'name' => 'konveksi_pilot_isolated',
            'driver' => $driver,
            'host' => (string) ($config['host'] ?? '127.0.0.1'),
            'port' => (int) ($config['port'] ?? 3306),
            'database' => (string) ($config['name'] ?? ''),
            'username' => (string) ($config['user'] ?? ''),
            'password' => (string) ($config['pass'] ?? ''),
            'unix_socket' => (string) ($config['socket'] ?? ''),
            'charset' => (string) ($config['charset'] ?? 'utf8mb4'),
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => [],
        ]);
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $path = (string) config('jago.konveksi_database_config_path', '');
        if ($path === '' || ! is_file($path)) {
            throw new RuntimeException('Konfigurasi database Konveksi tidak ditemukan.');
        }
        $config = require $path;
        if (! is_array($config)) {
            throw new RuntimeException('Konfigurasi database Konveksi tidak valid.');
        }

        return $this->config = $config;
    }
}
