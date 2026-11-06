<?php

namespace Tests\Feature;

use App\Auth\AuthenticatedUser;
use App\Services\Konveksi\KonveksiCollections;
use App\Services\Konveksi\KonveksiPilotReadService;
use App\Services\Konveksi\KonveksiSyncAndHppService;
use App\Services\Konveksi\KonveksiUserAccounts;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class KonveksiUserAccountsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['2026_11_10_000001_create_konveksi_support_tables', '2026_11_10_000002_create_konveksi_collection_tables', '2026_11_12_000001_create_konveksi_typed_tables'] as $name) {
            (require database_path('migrations/'.$name.'.php'))->up();
        }
        $this->assertSame(0, Artisan::call('konveksi:import-snapshot'));
        Schema::create('auth_users_v2', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('username')->unique();
            $table->string('name')->nullable();
            $table->text('password_hash');
            $table->string('role_id')->nullable();
            $table->string('status')->default('Aktif');
            $table->timestamps();
        });
        DB::table('auth_users_v2')->insert(['username' => 'owner', 'name' => 'Owner', 'password_hash' => password_hash('rahasia-owner', PASSWORD_BCRYPT), 'role_id' => 'ROLE-OWNER_SUPER_ADMIN', 'status' => 'Aktif']);
    }

    private function actor(string $role = 'ROLE-OWNER_SUPER_ADMIN'): AuthenticatedUser
    {
        return new AuthenticatedUser(['id' => 'u1', 'username' => 'owner', 'role_id' => $role]);
    }

    private function sync(array $rows, ?AuthenticatedUser $actor = null): void
    {
        app(KonveksiSyncAndHppService::class)->syncTab($actor ?? $this->actor(), 'LOCAL-COMPANY', 'pengguna', $rows);
    }

    /** @return list<array<string, mixed>> */
    private function stored(): array
    {
        return app(KonveksiCollections::class)->documents('pengguna');
    }

    public function test_a_typed_password_is_hashed_stored_and_becomes_a_working_login(): void
    {
        $this->sync([['id' => 'USR-9', 'username' => 'gudang', 'password' => 'sandi-gudang', 'nama' => 'Gudang', 'email' => '-', 'role_id' => 'ROLE-PEMBACA', 'status' => 'Aktif']]);

        $record = collect($this->stored())->firstWhere('username', 'gudang');
        $this->assertTrue(KonveksiUserAccounts::isHash($record['password']), 'the collection never keeps the clear text');
        $this->assertNotSame('sandi-gudang', $record['password']);
        $this->assertTrue(password_verify('sandi-gudang', $record['password']));

        $account = DB::table('auth_users_v2')->where('username', 'gudang')->first();
        $this->assertNotNull($account, 'the user list creates a real login account');
        $this->assertTrue(password_verify('sandi-gudang', $account->password_hash));
        $this->assertSame('ROLE-PEMBACA', $account->role_id);
        $this->assertSame('Aktif', $account->status);
    }

    public function test_an_empty_password_keeps_the_stored_one(): void
    {
        $this->sync([['id' => 'USR-9', 'username' => 'gudang', 'password' => 'sandi-gudang', 'role_id' => 'ROLE-PEMBACA', 'status' => 'Aktif']]);
        $before = collect($this->stored())->firstWhere('username', 'gudang')['password'];

        $this->sync([['id' => 'USR-9', 'username' => 'gudang', 'password' => '', 'nama' => 'Gudang Baru', 'role_id' => 'ROLE-PEMBACA', 'status' => 'Aktif']]);

        $record = collect($this->stored())->firstWhere('username', 'gudang');
        $this->assertSame($before, $record['password']);
        $this->assertSame('Gudang Baru', $record['nama']);
        $this->assertTrue(password_verify('sandi-gudang', DB::table('auth_users_v2')->where('username', 'gudang')->value('password_hash')));
    }

    public function test_a_reset_replaces_the_login_password(): void
    {
        $this->sync([['id' => 'USR-9', 'username' => 'gudang', 'password' => 'lama-lama', 'role_id' => 'ROLE-PEMBACA', 'status' => 'Aktif']]);
        $this->sync([['id' => 'USR-9', 'username' => 'gudang', 'password' => 'baru-baru', 'role_id' => 'ROLE-PEMBACA', 'status' => 'Aktif']]);

        $hash = DB::table('auth_users_v2')->where('username', 'gudang')->value('password_hash');
        $this->assertTrue(password_verify('baru-baru', $hash));
        $this->assertFalse(password_verify('lama-lama', $hash));
    }

    public function test_the_browser_never_receives_a_password(): void
    {
        $this->sync([['id' => 'USR-9', 'username' => 'gudang', 'password' => 'sandi-gudang', 'role_id' => 'ROLE-PEMBACA', 'status' => 'Aktif']]);

        $all = app(KonveksiPilotReadService::class)->allKnown();
        $this->assertNotEmpty($all['pengguna']);
        foreach ($all['pengguna'] as $record) {
            $this->assertSame('', $record['password']);
        }
        $this->assertStringNotContainsString('sandi-gudang', json_encode($all));
        $this->assertStringNotContainsString('$2y$', json_encode($all['pengguna']));
    }

    public function test_a_clear_text_password_found_in_old_data_is_never_trusted(): void
    {
        // The snapshot carries six-digit clear-text passwords; saving the list drops them (the account must be given a new password).
        $records = app(KonveksiCollections::class)->documents('pengguna');
        $this->assertNotEmpty($records);
        $this->sync($records);

        foreach ($this->stored() as $record) {
            $this->assertTrue($record['password'] === '' || KonveksiUserAccounts::isHash($record['password']));
        }
    }

    public function test_the_snapshot_import_and_the_cleanup_command_drop_clear_text_passwords(): void
    {
        foreach ($this->stored() as $record) {
            $this->assertTrue(($record['password'] ?? '') === '' || KonveksiUserAccounts::isHash((string) $record['password']), 'importer must not copy clear text');
        }

        // Simulate data from before the rule: a clear-text password straight in the table.
        $collections = app(KonveksiCollections::class);
        $collections->insert('pengguna', ['id' => 'USR-OLD', 'username' => 'lama', 'password' => '123456', 'role_id' => 'User', 'status' => 'Aktif'], 99);
        $this->assertSame(0, Artisan::call('konveksi:secure-passwords', ['--dry-run' => true]));
        $this->assertStringContainsString('1 password', Artisan::output());
        $this->assertSame('123456', collect($this->stored())->firstWhere('username', 'lama')['password']);

        $this->assertSame(0, Artisan::call('konveksi:secure-passwords'));
        $this->assertSame('', collect($this->stored())->firstWhere('username', 'lama')['password']);
    }

    public function test_removed_accounts_are_deactivated_but_the_owner_and_the_actor_stay(): void
    {
        $this->sync([['id' => 'USR-9', 'username' => 'gudang', 'password' => 'sandi-gudang', 'role_id' => 'ROLE-PEMBACA', 'status' => 'Aktif']]);
        $this->sync([['id' => 'USR-2', 'username' => 'owner', 'password' => '', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN', 'status' => 'Aktif']]);

        $this->assertSame('Nonaktif', DB::table('auth_users_v2')->where('username', 'gudang')->value('status'));
        $this->assertSame('Aktif', DB::table('auth_users_v2')->where('username', 'owner')->value('status'));
        $this->assertTrue(password_verify('rahasia-owner', DB::table('auth_users_v2')->where('username', 'owner')->value('password_hash')), 'the owner password is untouched');
    }

    public function test_an_administrator_cannot_hand_out_the_owner_role(): void
    {
        $this->expectException(DomainException::class);
        $this->sync([['id' => 'USR-9', 'username' => 'siapa', 'password' => 'sandi-siapa', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN', 'status' => 'Aktif']], $this->actor('ROLE-ADMINISTRATOR'));
    }
}
