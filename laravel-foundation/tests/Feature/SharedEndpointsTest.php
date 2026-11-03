<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use App\Pilots\Konveksi\KonveksiDocumentAndAiService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SharedEndpointsTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/jl-shared-'.bin2hex(random_bytes(4));
        config(['jago.documents.root' => $this->root, 'jago.backup.local_directory' => $this->root.'/backups', 'jago.backup.mysqldump' => null, 'jago.backup.external_directory' => null]);

        Schema::create('system_audit_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            foreach (['event_id', 'event_time', 'user_id', 'username', 'module', 'action', 'record_id', 'reference_id', 'before_json', 'after_json', 'status', 'notes'] as $column) {
                $table->text($column)->nullable();
            }
        });
        Schema::create('workspace_user_access', function (Blueprint $table): void {
            $table->string('user_key');
            $table->string('workspace');
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
        Schema::create('system_table_map', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('sheet_name');
            $table->string('api_key');
            $table->string('table_name');
            $table->unsignedInteger('source_row_count')->default(0);
        });
        Schema::create('jl_data_produk', function (Blueprint $table): void {
            $table->unsignedBigInteger('_row_id')->primary();
            $table->unsignedInteger('_row_order');
            $table->longText('_data_json');
        });
        Schema::create('auth_users_v2', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('username')->unique();
            $table->string('name')->nullable();
            $table->text('password_hash');
            $table->string('role_id')->nullable();
            $table->string('status')->default('aktif');
            $table->timestamps();
        });
        DB::table('auth_users_v2')->insert([
            ['username' => 'owner', 'name' => 'Owner', 'password_hash' => password_hash('secret', PASSWORD_BCRYPT), 'role_id' => 'ROLE-OWNER_SUPER_ADMIN'],
            ['username' => 'broken', 'name' => 'Broken', 'password_hash' => 'plaintext', 'role_id' => 'ROLE-PEMBACA'],
        ]);
        DB::table('system_table_map')->insert([
            ['sheet_name' => 'Produk', 'api_key' => 'produk', 'table_name' => 'jl_data_produk', 'source_row_count' => 2],
        ]);
        DB::table('jl_data_produk')->insert([['_row_id' => 1, '_row_order' => 1, '_data_json' => '{}']]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function actAs(string $role, string $workspace = 'konveksi'): void
    {
        $this->actingAs(new LegacyUser(['id' => 'u1', 'username' => 'u1', 'role_id' => $role, 'company_key' => 'LOCAL-COMPANY']), 'web');
        session(['workspace' => $workspace]);
    }

    private function storeDocument(): string
    {
        $this->actAs('ROLE-OWNER_SUPER_ADMIN');

        return app(KonveksiDocumentAndAiService::class)->saveBase64Document(auth('web')->user(), 'LOCAL-COMPANY', self::PNG, 'faktur.png', 'Faktur_Pembelian', 'TRX-1')['file_id'];
    }

    public function test_document_is_served_privately_with_safe_headers(): void
    {
        $id = $this->storeDocument();

        $response = $this->get('/api/konveksi-document.php?id='.$id)->assertOk();

        $this->assertSame(base64_decode(self::PNG), file_get_contents($response->baseResponse->getFile()->getPathname()));
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('inline; filename="faktur.png"', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_document_access_is_limited_to_the_konveksi_workspace_and_valid_registered_files(): void
    {
        $id = $this->storeDocument();

        $this->get('/api/konveksi-document.php?id=bad')->assertStatus(422);
        $this->get('/api/konveksi-document.php')->assertStatus(422);
        $this->get('/api/konveksi-document.php?id=DOC-'.str_repeat('0', 32))->assertNotFound();

        DB::table('document_files')->insert([
            'file_id' => 'DOC-'.str_repeat('1', 32), 'file_name' => 'x.txt', 'relative_path' => 'private/konveksi/../../outside.txt', 'mime_type' => 'text/plain',
        ]);
        file_put_contents($this->root.'/outside.txt', 'secret');
        $this->get('/api/konveksi-document.php?id=DOC-'.str_repeat('1', 32))->assertNotFound();

        DB::table('document_files')->insert([
            'file_id' => 'DOC-'.str_repeat('2', 32), 'file_name' => 'gone.png', 'relative_path' => 'private/konveksi/documents/none.png', 'mime_type' => 'image/png',
        ]);
        $this->get('/api/konveksi-document.php?id=DOC-'.str_repeat('2', 32))->assertNotFound();

        $this->actAs('ROLE-OWNER_SUPER_ADMIN', 'marketplace');
        $this->get('/api/konveksi-document.php?id='.$id)->assertForbidden();
    }

    public function test_document_requires_a_signed_in_user(): void
    {
        $this->getJson('/api/konveksi-document.php?id=DOC-'.str_repeat('0', 32))->assertUnauthorized();
    }

    public function test_diagnostics_are_owner_only_and_expose_no_secrets_or_paths(): void
    {
        $this->actAs('ROLE-OWNER_SUPER_ADMIN');

        $response = $this->getJson('/api/diagnostics.php')->assertOk();
        $response->assertJsonPath('status', 'FAIL')
            ->assertJsonPath('checks.migration.failed', 1)
            ->assertJsonPath('checks.migration.rows.0.source', 2)
            ->assertJsonPath('checks.migration.rows.0.actual', 1)
            ->assertJsonPath('checks.extensions.json', true)
            ->assertJsonPath('checks.backup.mysqldump_configured', false);
        $this->assertStringNotContainsString($this->root, $response->getContent());

        DB::table('system_table_map')->update(['source_row_count' => 1]);
        $this->getJson('/api/diagnostics.php')->assertOk()->assertJsonPath('status', 'PASS');

        foreach (['ROLE-ADMINISTRATOR', 'ROLE-KEUANGAN', 'ROLE-PEMBACA'] as $role) {
            $this->actAs($role);
            $this->getJson('/api/diagnostics.php')->assertForbidden();
            $this->getJson('/api/auth-diagnostic.php')->assertForbidden();
        }
    }

    public function test_auth_diagnostic_reports_hash_health_without_leaking_the_hash(): void
    {
        $this->actAs('ROLE-OWNER_SUPER_ADMIN');

        $owner = $this->getJson('/api/auth-diagnostic.php')->assertOk();
        $owner->assertJsonPath('diagnostic.0.username', 'owner')->assertJsonPath('diagnostic.0.hash_valid', true);
        $this->assertStringNotContainsString('password_hash', $owner->getContent());
        $this->assertStringNotContainsString('$2y$', $owner->getContent());

        $this->getJson('/api/auth-diagnostic.php?username=BROKEN')->assertOk()->assertJsonPath('diagnostic.0.hash_valid', false);
        $this->getJson('/api/auth-diagnostic.php?username=nobody')->assertOk()->assertJsonCount(0, 'diagnostic');
    }

    public function test_workspace_page_lists_allowed_workspaces_and_requires_login(): void
    {
        $this->actAs('ROLE-OWNER_SUPER_ADMIN');

        $response = $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml'])->get('/workspace.php')->assertOk();
        $response->assertJsonPath('component', 'Foundation')
            ->assertJsonPath('props.authenticated', true)
            ->assertJsonPath('props.workspaces', ['konveksi', 'marketplace']);

        $this->app['auth']->guard('web')->logout();
        $this->assertNotSame(200, $this->get('/workspace.php')->getStatusCode());
    }
}
