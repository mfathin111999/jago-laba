<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use App\Services\Konveksi\KonveksiDocumentAndAiService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class KonveksiDocumentStorageTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/jl-docs-'.bin2hex(random_bytes(4));
        config(['jago.documents.root' => $this->root]);

        $this->createAuditLogTable();
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
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function save(string $base64, string $name = 'faktur.png', string $type = 'Faktur_Pembelian'): array
    {
        return app(KonveksiDocumentAndAiService::class)->saveBase64Document(
            new LegacyUser(['id' => 'owner', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN']), 'A', $base64, $name, $type, 'TRX-1',
        );
    }

    public function test_document_bytes_are_written_to_private_storage_and_registered(): void
    {
        $result = $this->save('data:image/png;base64,'.self::PNG);

        $row = DB::table('document_files')->where('file_id', $result['file_id'])->first();
        $path = app(KonveksiDocumentAndAiService::class)->documentPath($row->relative_path);

        $this->assertFileExists($path);
        $this->assertSame(base64_decode(self::PNG), file_get_contents($path), 'the stored bytes must equal the upload');
        $this->assertSame(strlen(base64_decode(self::PNG)), (int) $row->size_bytes);
        $this->assertSame('image/png', $row->mime_type);
        $this->assertStringStartsWith('private/konveksi/documents/Faktur_Pembelian/', $row->relative_path);
        $this->assertStringEndsWith($result['file_id'].'.png', $row->relative_path);
        $this->assertStringStartsWith(str_replace('\\', '/', $this->root), str_replace('\\', '/', $path));
        $this->assertSame('TRX-1', $row->reference_id);
        $this->assertSame('PRIVATE_AUTHENTICATED', $result['sharing_status']);
    }

    public function test_type_and_file_name_cannot_escape_the_storage_directory(): void
    {
        $result = $this->save(self::PNG, '../../evil.png', '../../etc');

        $row = DB::table('document_files')->where('file_id', $result['file_id'])->first();
        $this->assertStringNotContainsString('..', $row->relative_path);
        $this->assertSame('evil.png', $row->file_name);
        $this->assertFileExists(app(KonveksiDocumentAndAiService::class)->documentPath($row->relative_path));
    }

    public function test_the_stored_file_is_removed_when_the_registry_insert_fails(): void
    {
        Schema::drop('document_files');
        Schema::create('document_files', function (Blueprint $table): void {
            $table->string('file_id')->unique();
        });

        $this->expectException(QueryException::class);
        try {
            $this->save('data:image/png;base64,'.self::PNG);
        } finally {
            $files = is_dir($this->root) ? File::allFiles($this->root) : [];
            $this->assertSame([], $files, 'no orphan file may stay behind');
        }
    }

    public function test_content_that_is_not_a_document_is_rejected_and_nothing_is_stored(): void
    {
        foreach ([base64_encode('<?php echo 1;'), base64_encode('<svg xmlns="http://www.w3.org/2000/svg"></svg>'), '###not-base64###'] as $payload) {
            try {
                $this->save($payload);
                $this->fail('A non-document upload must be rejected.');
            } catch (InvalidArgumentException) {
            }
        }

        $this->assertSame(0, DB::table('document_files')->count());
        $this->assertDirectoryDoesNotExist($this->root);
    }

    public function test_missing_registry_table_fails_instead_of_claiming_the_document_was_saved(): void
    {
        Schema::drop('document_files');

        try {
            $this->save(self::PNG);
            $this->fail('Saving without a registry must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Registri dokumen', $exception->getMessage());
        }

        $this->assertDirectoryDoesNotExist($this->root);
    }

    public function test_a_failed_registry_insert_removes_the_written_file(): void
    {
        Schema::drop('document_files');
        Schema::create('document_files', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('file_id')->unique();
        });

        try {
            $this->save(self::PNG);
            $this->fail('The registry insert should have failed.');
        } catch (\Throwable) {
        }

        $this->assertSame([], File::allFiles($this->root), 'an unregistered file must not be left behind');
    }
}
