<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AuthScopeTest extends TestCase
{
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

        Schema::create('system_users', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('username')->unique();
            $table->string('name')->nullable();
            $table->text('password_hash');
            $table->string('role_id')->nullable();
            $table->string('status')->default('aktif');
            $table->timestamps();
        });

        Schema::create('marketplace_stores', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_key');
            $table->timestamp('archived_at')->nullable();
            $table->string('authorization_status')->nullable();
        });

        DB::table('auth_users_v2')->insert([
            'username' => 'owner',
            'name' => 'Owner Test',
            'password_hash' => password_hash('owner-secret', PASSWORD_BCRYPT),
            'role_id' => 'ROLE-OWNER_SUPER_ADMIN',
            'status' => 'aktif',
        ]);

        DB::table('auth_users_v2')->insert([
            'username' => 'reader',
            'name' => 'Reader Test',
            'password_hash' => password_hash('reader-secret', PASSWORD_BCRYPT),
            'role_id' => 'ROLE-PEMBACA',
            'status' => 'active',
        ]);

        DB::table('system_users')->insert([
            'username' => 'legacy-system',
            'name' => 'System Legacy',
            'password_hash' => password_hash('system-secret', PASSWORD_BCRYPT),
            'role_id' => 'ROLE-OPERASIONAL',
            'status' => 'aktif',
        ]);

        DB::table('marketplace_stores')->insert([
            ['id' => 'store-local', 'company_key' => 'LOCAL-COMPANY', 'archived_at' => null, 'authorization_status' => 'connected'],
            ['id' => 'store-foreign', 'company_key' => 'OTHER-COMPANY', 'archived_at' => null, 'authorization_status' => 'connected'],
        ]);

        Route::middleware(['web', 'auth', 'company.context'])->get('/_test/company-context', function (Request $request) {
            return response()->json(['company_key' => $request->attributes->get('company_key')]);
        });

        Route::middleware(['web', 'auth', 'company.context', 'store.context:store_id'])
            ->get('/_test/stores/{store_id}', function (Request $request) {
                return response()->json(['store_id' => $request->attributes->get('store_id')]);
            });

        Route::middleware(['web', 'auth', 'can:finance.manage'])
            ->get('/_test/finance', fn () => response()->json(['ok' => true]));

        Route::middleware(['web', 'auth', 'workspace.context:marketplace'])
            ->get('/_test/marketplace', fn () => response()->json(['ok' => true]));
    }

    public function test_valid_legacy_user_is_authenticated_through_old_rpc_contract(): void
    {
        $response = $this->postJson('/api/rpc.php', [
            'method' => 'prosesLoginBackend',
            'args' => ['OWNER', 'owner-secret'],
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('result.status', 'success')
            ->assertJsonPath('result.auth_source', 'auth_users_v2');

        $this->getJson('/auth/me')
            ->assertOk()
            ->assertJsonPath('user.username', 'owner')
            ->assertJsonPath('context.allowed_workspaces.0', 'konveksi')
            ->assertJsonPath('context.allowed_workspaces.1', 'marketplace');
    }

    public function test_system_users_password_hash_is_supported_as_fallback(): void
    {
        $this->postJson('/api/rpc.php', [
            'method' => 'prosesLoginBackend',
            'args' => ['legacy-system', 'system-secret'],
        ])
            ->assertOk()
            ->assertJsonPath('result.status', 'success')
            ->assertJsonPath('result.auth_source', 'system_users');
    }

    public function test_expired_session_is_rejected(): void
    {
        $this->getJson('/auth/me')->assertStatus(401);
    }

    public function test_wrong_role_is_rejected_by_action_policy(): void
    {
        $this->loginAs('reader', 'reader-secret');

        $this->getJson('/_test/finance')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ROLE_FORBIDDEN');
    }

    public function test_foreign_company_context_is_rejected(): void
    {
        $this->loginAs('owner', 'owner-secret');

        $this->getJson('/_test/company-context?company_key=OTHER-COMPANY')
            ->assertStatus(403)
            ->assertJsonPath('code', 'COMPANY_FORBIDDEN');
    }

    public function test_foreign_store_is_rejected(): void
    {
        $this->loginAs('owner', 'owner-secret');

        $this->getJson('/_test/stores/store-foreign')
            ->assertStatus(403)
            ->assertJsonPath('code', 'STORE_FORBIDDEN');

        $this->getJson('/_test/stores/store-local')
            ->assertOk()
            ->assertJsonPath('store_id', 'store-local');
    }

    public function test_invalid_csrf_is_rejected_for_authenticated_legacy_rpc(): void
    {
        $this->loginAs('owner', 'owner-secret');

        $this->withHeader('X-CSRF-TOKEN', 'invalid-token')
            ->postJson('/api/rpc.php', ['method' => 'loadSemuaData', 'args' => []])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CSRF_INVALID');
    }

    public function test_workspace_can_be_switched_and_is_required_by_scope_middleware(): void
    {
        $this->loginAs('owner', 'owner-secret');
        $token = csrf_token();

        $this->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/api/workspace.php', ['workspace' => 'marketplace'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('workspace', 'marketplace');

        $this->getJson('/_test/marketplace')->assertOk();

        $this->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/api/workspace.php', ['workspace' => 'konveksi'])
            ->assertOk()
            ->assertJsonPath('workspace', 'konveksi');

        $this->getJson('/_test/marketplace')
            ->assertStatus(403)
            ->assertJsonPath('code', 'WORKSPACE_NOT_ACTIVE');

        $this->getJson('/auth/me')
            ->assertOk()
            ->assertJsonPath('context.workspace', 'konveksi');
    }

    private function loginAs(string $username, string $password): void
    {
        $this->postJson('/api/rpc.php', [
            'method' => 'prosesLoginBackend',
            'args' => [$username, $password],
        ])->assertOk()->assertJsonPath('result.status', 'success');
    }

    public function test_login_throttle_is_shared_by_native_and_legacy_login_routes(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/auth/login', ['username' => 'throttle-owner', 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/rpc.php', ['method' => 'prosesLoginBackend', 'args' => ['THROTTLE-OWNER', 'wrong']])
            ->assertStatus(429)->assertJsonPath('code', 'LOGIN_THROTTLED')->assertHeader('Retry-After');
    }

    public function test_login_rotates_session_and_logout_revokes_the_session(): void
    {
        $this->get('/login')->assertOk();
        $before = session()->getId();
        $this->postJson('/auth/login', ['username' => 'owner', 'password' => 'owner-secret'])->assertOk();
        $this->assertNotSame($before, session()->getId());
        $this->get('/workspace.php')->assertOk();
        $this->postJson('/auth/logout')->assertOk();
        $this->getJson('/auth/me')->assertUnauthorized();
    }

    public function test_disabled_user_cannot_login_and_secrets_are_not_serialized(): void
    {
        DB::table('auth_users_v2')->where('username', 'owner')->update(['status' => 'disabled']);
        $this->postJson('/auth/login', ['username' => 'owner', 'password' => 'owner-secret'])->assertUnauthorized();
        $user = new LegacyUser(['id' => 1, 'username' => 'safe', 'password_hash' => 'secret', 'api_key' => 'private', 'company_key' => 'COMPANY-A']);
        $this->assertArrayNotHasKey('api_key', $user->toArray());
        $this->assertArrayNotHasKey('password_hash', $user->toArray());
        $this->assertSame('COMPANY-A', $user->withLegacyContext(['company_key' => 'COMPANY-B'])->getCompanyKey());
    }

    public function test_company_context_rejects_malformed_or_conflicting_scope_selectors(): void
    {
        $this->loginAs('owner', 'owner-secret');
        $this->getJson('/_test/company-context?company_key[]=LOCAL-COMPANY')->assertForbidden();
        $this->withHeader('X-Company-Key', 'OTHER-COMPANY')
            ->getJson('/_test/company-context?company_key=LOCAL-COMPANY')->assertForbidden();
    }

    public function test_explicit_workspace_registry_overrides_role_defaults_and_clears_store_context(): void
    {
        $this->createWorkspaceAccessTable();
        DB::table('workspace_user_access')->insert(['user_key' => 'AUTHV2-1', 'workspace' => 'konveksi']);
        $this->loginAs('owner', 'owner-secret');
        $this->postJson('/api/workspace.php', ['workspace' => 'marketplace'])->assertForbidden();
        $this->withSession(['store_id' => 'store-local', 'workspace_filters' => ['secret' => 'old']])
            ->postJson('/api/workspace.php', ['workspace' => 'konveksi'])->assertOk();
        $this->assertNull(session('store_id'));
        $this->assertNull(session('workspace_filters'));
    }

    #[DataProvider('roleMatrix')]
    public function test_all_legacy_roles_have_the_expected_finance_boundary(string $role, bool $allowed): void
    {
        DB::table('auth_users_v2')->where('username', 'owner')->update(['role_id' => $role]);
        $this->loginAs('owner', 'owner-secret');
        $this->getJson('/_test/finance')->assertStatus($allowed ? 200 : 403);
    }

    public static function roleMatrix(): array
    {
        return [
            'owner' => ['ROLE-OWNER_SUPER_ADMIN', true],
            'administrator' => ['ROLE-ADMINISTRATOR', true],
            'finance' => ['ROLE-KEUANGAN', true],
            'operations' => ['ROLE-OPERASIONAL', false],
            'reader' => ['ROLE-PEMBACA', false],
            'unrecognized' => ['ROLE-UNRECOGNIZED', false],
        ];
    }

    public function test_store_selection_requires_workspace_csrf_and_same_company_non_archived_store(): void
    {
        $this->loginAs('owner', 'owner-secret');
        $this->postJson('/auth/store', ['store_id' => 'store-local'])->assertForbidden();
        $this->postJson('/auth/workspace', ['workspace' => 'marketplace'])->assertOk();
        $this->withHeader('X-CSRF-TOKEN', 'bad')->postJson('/auth/store', ['store_id' => 'store-local'])->assertStatus(419);
        $this->withHeader('X-CSRF-TOKEN', csrf_token())->postJson('/auth/store', ['store_id' => 'store-foreign'])->assertForbidden();
        $this->postJson('/auth/store', ['store_id' => 'store-local'])->assertOk()->assertJsonPath('store_id', 'store-local');
        $this->assertSame('store-local', session('store_id'));
        DB::table('marketplace_stores')->where('id', 'store-local')->update(['archived_at' => now()]);
        $this->postJson('/auth/store', ['store_id' => 'store-local'])->assertForbidden();
    }
}
