<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use App\Pilots\Konveksi\KonveksiBackupService;
use App\Pilots\Konveksi\KonveksiPurchaseWriteService;
use App\Pilots\Konveksi\KonveksiSalesWriteService;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

final class KonveksiRetryRpcTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $workdir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workdir = sys_get_temp_dir().'/jl-retry-'.bin2hex(random_bytes(4));
        $this->app->useStoragePath($this->workdir.'/storage');
        config(['jago.documents.root' => $this->workdir.'/docs', 'jago.backup.local_directory' => $this->workdir.'/backups', 'jago.backup.external_directory' => null, 'jago.backup.mysqldump' => null]);

        Schema::create('system_audit_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            foreach (['event_id', 'event_time', 'user_id', 'username', 'module', 'action', 'record_id', 'reference_id', 'before_json', 'after_json', 'status', 'notes'] as $column) {
                $table->text($column)->nullable();
            }
        });
        Schema::create('system_table_map', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('sheet_name');
            $table->string('api_key');
            $table->string('table_name');
        });
        Schema::create('jl_data_penjualan', function (Blueprint $table): void {
            $table->unsignedBigInteger('_row_id')->primary();
            $table->unsignedInteger('_row_order');
            $table->longText('_data_json');
        });
        Schema::create('document_files', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('file_id')->unique();
            $table->string('document_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->string('file_name');
            $table->text('relative_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->dateTime('created_at')->nullable();
        });
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
        DB::table('system_table_map')->insert(['sheet_name' => 'Penjualan', 'api_key' => 'penjualan', 'table_name' => 'jl_data_penjualan']);
        DB::table('jl_data_penjualan')->insert([
            ['_row_id' => 1, '_row_order' => 1, '_data_json' => json_encode(['id_penjualan' => 'S1', 'no_penjualan' => 'INV-1', 'total' => 5000, 'backup_status' => 'FAILED'])],
            ['_row_id' => 2, '_row_order' => 2, '_data_json' => json_encode(['id_penjualan' => 'S2', 'no_penjualan' => 'INV-2', 'backup_status' => 'DONE'])],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workdir);
        parent::tearDown();
    }

    private function user(): LegacyUser
    {
        return new LegacyUser(['id' => 'owner', 'username' => 'owner', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN']);
    }

    public function test_retry_sale_backup_marks_only_that_sale_pending_and_keeps_its_data(): void
    {
        $result = app(KonveksiSalesWriteService::class)->retryBackup($this->user(), 'A', ['id_penjualan' => 'S1']);

        $this->assertSame('success', $result['status']);
        $first = json_decode(DB::table('jl_data_penjualan')->where('_row_id', 1)->value('_data_json'), true);
        $this->assertSame('PENDING', $first['backup_status']);
        $this->assertSame(5000, $first['total']);
        $this->assertNotEmpty($first['backup_at']);
        $this->assertSame('DONE', json_decode(DB::table('jl_data_penjualan')->where('_row_id', 2)->value('_data_json'), true)['backup_status']);
        $this->assertDatabaseHas('system_audit_logs', ['action' => 'RETRY_SALE_BACKUP', 'record_id' => 'S1']);
    }

    public function test_retry_sale_backup_rejects_unknown_or_blank_ids(): void
    {
        $service = app(KonveksiSalesWriteService::class);

        try {
            $service->retryBackup($this->user(), 'A', ['id_penjualan' => 'NOPE']);
            $this->fail('Unknown sale must be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('Penjualan tidak ditemukan.', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $service->retryBackup($this->user(), 'A', []);
    }

    public function test_retry_purchase_document_stores_the_invoice_under_the_transaction(): void
    {
        $result = app(KonveksiPurchaseWriteService::class)->retryDocument($this->user(), 'A', [
            'id_transaksi' => 'TRX-9',
            'file_invoice' => ['base64' => 'data:image/png;base64,'.self::PNG, 'fileName' => 'faktur-9.png', 'mimeType' => 'image/png'],
        ]);

        $row = DB::table('document_files')->where('file_id', $result['file_id'])->first();
        $this->assertSame('TRX-9', $row->reference_id);
        $this->assertSame('Faktur_Pembelian', $row->document_type);
        $this->assertSame('faktur-9.png', $row->file_name);
        $this->assertFileExists($this->workdir.'/docs/'.$row->relative_path);
    }

    public function test_retry_purchase_document_requires_the_invoice_file(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File faktur tidak tersedia.');
        app(KonveksiPurchaseWriteService::class)->retryDocument($this->user(), 'A', ['id_transaksi' => 'TRX-9', 'file_invoice' => []]);
    }

    public function test_scheduled_snapshot_runs_once_after_the_marker_is_a_minute_old(): void
    {
        $service = app(KonveksiBackupService::class);
        $marker = storage_path('app/private/pending_snapshot.json');

        $this->assertNull($service->processPending(), 'nothing is due when nothing was requested');

        $this->assertSame('scheduled', $service->scheduleSmart($this->user())['status']);
        $this->assertFileExists($marker);
        $this->assertNull($service->processPending(), 'a fresh request waits at least a minute');
        $this->assertFileExists($marker);

        // A burst of further requests must not push the due time back.
        touch($marker, time() - 120);
        $mtime = filemtime($marker);
        $service->scheduleSmart($this->user());
        $this->assertSame($mtime, filemtime($marker));

        $result = $service->processPending();
        $this->assertSame('success', $result['status']);
        $this->assertSame('SMART', $result['mode']);
        $this->assertFileExists($result['snapshot_file']);
        $this->assertFileDoesNotExist($marker);
        $this->assertSame('SMART', DB::table('backup_log')->value('mode'));
        $this->assertDatabaseHas('system_audit_logs', ['action' => 'BACKUP_DATABASE', 'username' => 'system']);
        $this->assertNull($service->processPending(), 'the request is consumed');
    }
}
