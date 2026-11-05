<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use App\Http\Controllers\LegacyRpcController;
use App\Services\Konveksi\KonveksiBackupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class KonveksiBackupTest extends TestCase
{
    private string $directory;

    private string $mirror;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/jl-backup-'.bin2hex(random_bytes(4));
        $this->mirror = sys_get_temp_dir().'/jl-mirror-'.bin2hex(random_bytes(4));
        config(['jago.backup.local_directory' => $this->directory, 'jago.backup.external_directory' => null, 'jago.backup.mysqldump' => null]);

        $this->createAuditLogTable();
        Schema::create('backup_log', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('backup_id');
            $table->string('mode');
            $table->string('status');
            $table->text('sql_file')->nullable();
            $table->text('snapshot_file')->nullable();
            $table->text('manifest_file')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum')->nullable();
            $table->text('message')->nullable();
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('system_table_map', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('sheet_name');
            $table->string('api_key');
            $table->string('table_name');
        });
        foreach (['jl_data_produk', 'jl_data_bahan'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('_row_id')->primary();
                $table->unsignedInteger('_row_order');
                $table->longText('_data_json');
            });
        }
        DB::table('system_table_map')->insert([
            ['sheet_name' => 'Produk', 'api_key' => 'produk', 'table_name' => 'jl_data_produk'],
            ['sheet_name' => 'Bahan', 'api_key' => 'bahan', 'table_name' => 'jl_data_bahan'],
        ]);
        DB::table('jl_data_produk')->insert([
            ['_row_id' => 2, '_row_order' => 2, '_data_json' => json_encode(['id' => 'P2', 'nama' => 'Kemeja "Baju" é'])],
            ['_row_id' => 1, '_row_order' => 1, '_data_json' => json_encode(['id' => 'P1', 'nama' => 'Celana'])],
        ]);
        DB::table('jl_data_bahan')->insert([['_row_id' => 1, '_row_order' => 1, '_data_json' => json_encode(['id' => 'B1'])]]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        File::deleteDirectory($this->mirror);
        parent::tearDown();
    }

    private function service(): KonveksiBackupService
    {
        return app(KonveksiBackupService::class);
    }

    private function user(): LegacyUser
    {
        return new LegacyUser(['id' => 'owner', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN', 'company_key' => 'A']);
    }

    public function test_the_browser_never_receives_server_paths_for_backups(): void
    {
        $controller = app(LegacyRpcController::class);
        $method = new \ReflectionMethod($controller, 'publicBackup');

        $clean = $method->invoke($controller, [
            'backup_id' => 'BKP-1', 'sql_file' => null, 'snapshot_file' => 'C:\xampp\private\backups/MANUAL/2026/11/jago_laba_1.json.gz',
            'manifest_file' => '/var/www/private/backups/jago_laba_1.manifest.json', 'url' => 'file:///C:/xampp/private/backups',
            'external_files' => ['D:/mirror/MANUAL/jago_laba_1.json.gz'],
        ]);

        $this->assertSame(['jago_laba_1.json.gz'], [basename($clean['snapshot_file'])]);
        $this->assertSame('jago_laba_1.json.gz', $clean['snapshot_file']);
        $this->assertSame('jago_laba_1.manifest.json', $clean['manifest_file']);
        $this->assertNull($clean['sql_file']);
        $this->assertSame('#', $clean['url']);
        $this->assertSame(['jago_laba_1.json.gz'], $clean['external_files']);
        $this->assertStringNotContainsString('xampp', json_encode($clean));
        $this->assertSame(['error' => 'Salinan eksternal gagal.'], $method->invoke($controller, ['external_files' => ['error' => 'copy(D:/secret/path) failed']])['external_files']);
    }

    public function test_backup_writes_a_readable_snapshot_manifest_and_truthful_log(): void
    {
        $result = $this->service()->create($this->user(), 'manual');

        $this->assertSame('success', $result['status']);
        $this->assertSame(3, $result['jumlah_row']);
        $this->assertFileExists($result['snapshot_file']);
        $this->assertFileExists($result['manifest_file']);
        $this->assertNull($result['sql_file']);

        $snapshot = json_decode(gzdecode((string) file_get_contents($result['snapshot_file'])), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($result['backup_id'], $snapshot['metadata']['backup_id']);
        $this->assertSame(['P1', 'P2'], array_column($snapshot['tables']['Produk'], 'id'), 'rows keep their stored order');
        $this->assertSame('Kemeja "Baju" é', $snapshot['tables']['Produk'][1]['nama']);
        $this->assertSame([['id' => 'B1']], $snapshot['tables']['Bahan']);

        $manifest = json_decode((string) file_get_contents($result['manifest_file']), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(hash_file('sha256', $result['snapshot_file']), $manifest['checksums'][basename($result['snapshot_file'])]);
        $this->assertSame(3, $manifest['total_rows']);

        $log = DB::table('backup_log')->first();
        $this->assertSame('Berhasil', $log->status);
        $this->assertSame(3, (int) $log->total_rows);
        $this->assertSame(filesize($result['snapshot_file']) + filesize($result['manifest_file']), (int) $log->size_bytes);
        $this->assertNotSame(1024, (int) $log->size_bytes, 'size must be measured, not a placeholder');
        $this->assertSame('MANUAL', $log->mode);
        $this->assertDatabaseHas('system_audit_logs', ['action' => 'BACKUP_DATABASE', 'record_id' => $result['backup_id']]);

        $history = $this->service()->history();
        $this->assertCount(1, $history);
        $this->assertSame($result['backup_id'], $history[0]['backup_id']);
    }

    public function test_external_copy_is_made_when_configured(): void
    {
        config(['jago.backup.external_directory' => $this->mirror]);

        $result = $this->service()->create($this->user(), 'MANUAL');

        $this->assertCount(2, $result['external_files']);
        foreach ($result['external_files'] as $copy) {
            $this->assertFileExists($copy);
        }
        $this->assertSame(hash_file('sha256', $result['snapshot_file']), hash_file('sha256', $result['external_files'][0]));
    }

    public function test_missing_mapped_tables_are_reported_but_unsafe_mappings_fail_the_backup(): void
    {
        DB::table('system_table_map')->insert(['sheet_name' => 'Belum', 'api_key' => 'belum', 'table_name' => 'jl_data_belum']);
        $result = $this->service()->create($this->user(), 'MANUAL');
        $manifest = json_decode((string) file_get_contents($result['manifest_file']), true);
        $this->assertSame(['jl_data_belum'], $manifest['missing_tables']);
        $this->assertStringContainsString('belum ada', (string) DB::table('backup_log')->latest('id')->value('message'));

        DB::table('system_table_map')->insert(['sheet_name' => 'Jahat', 'api_key' => 'jahat', 'table_name' => 'users; DROP TABLE x']);
        try {
            $this->service()->create($this->user(), 'MANUAL');
            $this->fail('An unsafe table mapping must abort the backup.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Backup gagal', $exception->getMessage());
        }
    }

    public function test_a_failed_backup_is_logged_as_failed_and_leaves_no_partial_files(): void
    {
        DB::table('system_table_map')->insert(['sheet_name' => 'Jahat', 'api_key' => 'jahat', 'table_name' => 'bad table']);

        try {
            $this->service()->create($this->user(), 'MANUAL');
            $this->fail('Backup should have failed.');
        } catch (RuntimeException) {
        }

        $log = DB::table('backup_log')->first();
        $this->assertSame('Gagal', $log->status);
        $this->assertSame(0, (int) $log->size_bytes);
        $this->assertSame([], File::allFiles($this->directory), 'partial snapshot must be removed');
        $this->assertDatabaseMissing('system_audit_logs', ['action' => 'BACKUP_DATABASE']);
    }

    public function test_unwritable_backup_location_fails_instead_of_claiming_success(): void
    {
        $blocker = tempnam(sys_get_temp_dir(), 'jl');
        config(['jago.backup.local_directory' => $blocker.'/inside']);

        try {
            $this->service()->create($this->user(), 'MANUAL');
            $this->fail('Backup into an unwritable location must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Backup gagal', $exception->getMessage());
        } finally {
            @unlink($blocker);
        }

        $this->assertSame('Gagal', DB::table('backup_log')->value('status'));
    }

    public function test_mode_is_sanitised_for_the_file_system(): void
    {
        $result = $this->service()->create($this->user(), '../../etc');

        $this->assertSame('ETC', $result['mode']);
        $this->assertStringStartsWith(str_replace('\\', '/', $this->directory), str_replace('\\', '/', $result['snapshot_file']));
    }
}
