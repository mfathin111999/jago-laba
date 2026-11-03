<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class KonveksiRpcAuthorizationTest extends TestCase
{
    private const READ_METHODS = ['loadSemuaData', 'getRiwayatBackupJagoLaba', 'getGeminiApiKeyStatusServerRpc'];

    private const WRITE_METHODS = [
        'backupSekarangJagoLaba', 'buatBackupManualSekarang', 'jadwalkanSnapshotRinganJagoLaba', 'retryBackupPenjualanProduksiServer', 'retryUploadPurchaseDocumentServerRpc', 'saveGeminiApiKeyServerRpc', 'postPenjualanProduksiServer',
        'voidPenjualanProduksiServer', 'bayarHutangKonveksi', 'saveDocumentFileServer', 'syncTab', 'migrateInventoryIdStokItemServer',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('auth_users_v2', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('username')->unique();
            $table->string('name')->nullable();
            $table->text('password_hash');
            $table->string('role_id')->nullable();
            $table->string('status')->default('aktif');
            $table->timestamps();
        });
        Schema::create('workspace_user_access', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('user_key');
            $table->string('workspace');
        });
        foreach (['owner' => 'ROLE-OWNER_SUPER_ADMIN', 'admin' => 'ROLE-ADMINISTRATOR', 'reader' => 'ROLE-PEMBACA', 'finance' => 'ROLE-KEUANGAN', 'ops' => 'ROLE-OPERASIONAL'] as $username => $role) {
            DB::table('auth_users_v2')->insert([
                'username' => $username, 'name' => ucfirst($username), 'password_hash' => password_hash($username.'-secret', PASSWORD_BCRYPT),
                'role_id' => $role, 'status' => 'aktif',
            ]);
        }
    }

    private function login(string $username): void
    {
        $this->postJson('/api/rpc.php', ['method' => 'prosesLoginBackend', 'args' => [$username, $username.'-secret']])
            ->assertOk()->assertJsonPath('result.status', 'success');
        $this->withHeader('X-CSRF-TOKEN', csrf_token())->postJson('/api/workspace.php', ['workspace' => 'konveksi'])->assertOk();
    }

    private function rpc(string $method, array $args = []): TestResponse
    {
        return $this->withHeader('X-CSRF-TOKEN', csrf_token())->postJson('/api/rpc.php', ['method' => $method, 'args' => $args]);
    }

    public function test_non_admin_roles_cannot_call_any_konveksi_write_method(): void
    {
        foreach (['reader', 'finance', 'ops'] as $username) {
            $this->flushSession();
            $this->login($username);

            foreach (self::WRITE_METHODS as $method) {
                $this->rpc($method, ['x'])
                    ->assertStatus(403)
                    ->assertJsonPath('error.code', 'ROLE_FORBIDDEN');
            }
        }
    }

    public function test_every_role_with_konveksi_access_can_still_read(): void
    {
        foreach (['reader', 'finance', 'owner'] as $username) {
            $this->flushSession();
            $this->login($username);

            foreach (self::READ_METHODS as $method) {
                $this->assertNotContains($this->rpc($method)->getStatusCode(), [401, 403], $username.' must be able to call '.$method);
            }
        }
    }

    public function test_owner_and_administrator_pass_authorization_for_write_methods(): void
    {
        foreach (['owner', 'admin'] as $username) {
            $this->flushSession();
            $this->login($username);

            foreach (self::WRITE_METHODS as $method) {
                $this->assertNotSame(403, $this->rpc($method, ['x'])->getStatusCode(), $username.' must not be blocked on '.$method);
            }
        }
    }

    public function test_konveksi_rpc_requires_the_konveksi_workspace_to_be_active(): void
    {
        $this->login('owner');
        $this->withHeader('X-CSRF-TOKEN', csrf_token())->postJson('/api/workspace.php', ['workspace' => 'marketplace'])->assertOk();

        foreach (['loadSemuaData', 'backupSekarangJagoLaba'] as $method) {
            $this->rpc($method)->assertStatus(403)->assertJsonPath('error.code', 'WORKSPACE_NOT_ACTIVE');
        }
    }

    public function test_login_and_unknown_methods_are_not_affected_by_the_new_checks(): void
    {
        $this->postJson('/api/rpc.php', ['method' => 'prosesLoginBackend', 'args' => ['reader', 'reader-secret']])
            ->assertOk()->assertJsonPath('result.status', 'success');

        $this->login('owner');
        $this->rpc('metodeTidakAda')->assertStatus(503);
    }
}
