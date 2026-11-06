<?php

namespace App\Console\Commands;

use App\Database\KonveksiSchema;
use App\Services\Konveksi\Storage\JsonCollectionDriver;
use App\Services\Konveksi\Typed\KonveksiTypedMigrator;
use App\Services\Konveksi\TypedCollections;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

/**
 * Copies the Konveksi data into its own database with its own restricted MySQL user (port of bin/konveksi-setup.php).
 * The schema of the new database comes from the same definitions as the migrations, the rows are copied and compared
 * one by one, and the new user is proven unable to read Marketplace tables. Nothing in the source database is
 * changed, and an existing target is never overwritten. With --activate the connection file is written, which makes
 * the application use the new database.
 */
final class KonveksiIsolateCommand extends Command
{
    protected $signature = 'konveksi:isolate
        {--prepare : Required confirmation that a copy should be made}
        {--activate : Write the connection file so the application switches to the new database}
        {--target=jago_laba_konveksi : Name of the new database}';

    protected $description = 'Copy Konveksi data into a separate database with its own restricted user';

    public function handle(DatabaseManager $manager): int
    {
        $path = (string) config('jago.konveksi_database_config_path');
        if (is_file($path)) {
            $this->line('Konveksi sudah memakai database terpisah (konfigurasi ada). Tidak ada yang diubah.');

            return self::SUCCESS;
        }
        if (! $this->option('prepare')) {
            $this->error('Tambahkan --prepare untuk membuat salinan (dan --activate untuk memakainya).');

            return self::INVALID;
        }

        $source = $manager->connection();
        $sourceName = (string) $source->getDatabaseName();
        $target = (string) $this->option('target');
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $target) || $target === $sourceName) {
            $this->error('Nama database sumber/tujuan tidak valid.');

            return self::FAILURE;
        }

        $lock = 'jl-konveksi-write-'.sha1($sourceName);
        if ((int) ($source->selectOne('SELECT GET_LOCK(?, 30) AS got', [$lock])->got ?? 0) !== 1) {
            $this->error('Tunggu transaksi berjalan sebelum migrasi.');

            return self::FAILURE;
        }

        try {
            $result = $this->isolate($manager, $source, $sourceName, $target, $path);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $source->select('SELECT RELEASE_LOCK(?)', [$lock]);
        }

        $this->info(sprintf(
            'Terverifikasi: %d koleksi, %d baris, isolasi kredensial terbukti. %s',
            $result['collections'],
            $result['rows'],
            $result['activated'] ? 'Konfigurasi ditulis: aplikasi kini memakai '.$target.'.' : 'Belum diaktifkan (tambahkan --activate).',
        ));

        return self::SUCCESS;
    }

    /** @return array{collections: int, rows: int, activated: bool} */
    private function isolate(DatabaseManager $manager, Connection $source, string $sourceName, string $target, string $path): array
    {
        $base = $source->getConfig();
        $server = $manager->build(array_merge($base, ['name' => 'konveksi_isolate_server', 'database' => null]));

        if ($server->selectOne('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = ?', [$target])->n > 0) {
            throw new RuntimeException('Database tujuan sudah berisi tabel. Audit diperlukan sebelum melanjutkan; data tidak ditimpa.');
        }
        $server->statement("CREATE DATABASE IF NOT EXISTS `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $admin = $manager->build(array_merge($base, ['name' => 'konveksi_isolate_target', 'database' => $target]));

        $schema = new KonveksiSchema;
        $schema->createSupportTables($admin->getSchemaBuilder());
        $schema->createCollectionTables($admin->getSchemaBuilder());
        app(KonveksiTypedMigrator::class)->createTables($admin);
        $typed = app(TypedCollections::class);
        $admin->table('system_table_map')->delete();

        $rows = [];
        $admin->beginTransaction();
        try {
            $mapped = $source->table('system_table_map')->get()->map(fn ($row) => (array) $row)
                ->filter(fn (array $row) => isset(KonveksiSchema::mappedCollections()[$row['table_name']]) || $typed->driverFor((string) $row['table_name']) !== null)->values();
            foreach ($mapped as $map) {
                $table = $map['table_name'];
                $definition = collect($typed->definitions())->first(fn ($candidate) => $candidate->table === $table);
                $this->copy($source, $admin, $table, $definition?->tableColumns() ?? KonveksiSchema::COLLECTION_COLUMNS);
                $this->assertSame($source, $admin, $table, $typed);
                $admin->table('system_table_map')->insert($map);
                $rows[] = (int) $admin->table($table)->count();
            }
            $schema->seedTableMap($admin);
            foreach (['document_files', 'backup_log', 'idempotency_keys'] as $table) {
                $this->copy($source, $admin, $table, KonveksiSchema::SUPPORT_COLUMNS[$table]);
            }
            $this->copy($source, $admin, 'system_settings', KonveksiSchema::SUPPORT_COLUMNS['system_settings'], fn ($query) => $query->whereIn('setting_key', ['GEMINI_API_KEY', 'GEMINI_MODEL']));
            $admin->commit();
        } catch (Throwable $exception) {
            $admin->rollBack();
            throw $exception;
        }

        $username = 'jl_konv_'.bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(32));
        $pdo = $server->getPdo();
        foreach (['127.0.0.1', 'localhost'] as $host) {
            $account = $pdo->quote($username).'@'.$pdo->quote($host);
            $server->unprepared('CREATE USER '.$account.' IDENTIFIED BY '.$pdo->quote($password));
            $server->unprepared("GRANT SELECT,INSERT,UPDATE,DELETE ON `{$target}`.* TO ".$account);
        }

        $config = [
            'driver' => 'mysql', 'host' => $base['host'], 'port' => (int) $base['port'], 'name' => $target, 'user' => $username, 'pass' => $password,
            'socket' => (string) ($base['unix_socket'] ?? ''), 'charset' => 'utf8mb4', 'isolated' => true,
            'company_key' => (string) env('JL_COMPANY_KEY', 'LOCAL-COMPANY'), 'activated_at' => date(DATE_ATOM),
        ];
        $this->proveIsolation($manager, $config, $sourceName);

        $activated = (bool) $this->option('activate');
        if ($activated) {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }
            $temporary = $path.'.tmp';
            file_put_contents($temporary, "<?php\nreturn ".var_export($config, true).";\n", LOCK_EX);
            if (! rename($temporary, $path)) {
                throw new RuntimeException('Aktivasi konfigurasi gagal.');
            }
        }

        return ['collections' => count($rows), 'rows' => array_sum($rows), 'activated' => $activated];
    }

    /** @param list<string> $columns */
    private function copy(Connection $source, Connection $target, string $table, array $columns, ?callable $scope = null): void
    {
        if (! $source->getSchemaBuilder()->hasTable($table)) {
            return;
        }
        $common = array_values(array_intersect($columns, $source->getSchemaBuilder()->getColumnListing($table)));
        $query = $source->table($table)->select($common);
        if ($scope !== null) {
            $scope($query);
        }
        $query->orderBy($common[0])->chunk(500, function ($chunk) use ($target, $table): void {
            $target->table($table)->insert($chunk->map(fn ($row) => (array) $row)->all());
        });
    }

    private function assertSame(Connection $source, Connection $target, string $table, TypedCollections $typed): void
    {
        $driver = $typed->driverFor($table) ?? new JsonCollectionDriver;
        if ($driver->rows($source, $table) !== $driver->rows($target, $table)) {
            throw new RuntimeException("Rekonsiliasi tabel {$table} gagal.");
        }
    }

    /** @param array<string, mixed> $config */
    private function proveIsolation(DatabaseManager $manager, array $config, string $sourceName): void
    {
        $test = $manager->build([
            'driver' => 'mysql', 'host' => $config['host'], 'port' => $config['port'], 'database' => $config['name'],
            'username' => $config['user'], 'password' => $config['pass'], 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
        ]);
        $test->table('system_table_map')->count();
        try {
            $test->select("SELECT id FROM `{$sourceName}`.marketplace_stores LIMIT 1");
        } catch (Throwable) {
            return;
        }
        throw new RuntimeException('Isolasi kredensial database gagal.');
    }
}
