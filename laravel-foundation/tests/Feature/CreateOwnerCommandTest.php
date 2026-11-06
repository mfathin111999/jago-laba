<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CreateOwnerCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['auth_users_v2', 'system_users'] as $table) {
            Schema::create($table, function (Blueprint $t): void {
                $t->id();
                $t->string('username')->unique();
                $t->string('name')->nullable();
                $t->string('password_hash');
                $t->string('role_id')->default('ROLE-OWNER_SUPER_ADMIN');
                $t->string('status')->default('Aktif');
                $t->boolean('must_change_password')->default(false);
            });
        }
    }

    public function test_it_creates_an_active_owner_with_a_verifiable_password(): void
    {
        $this->assertSame(0, Artisan::call('jago:create-owner', ['--password' => 'rahasia-123', '--no-interaction' => true]));

        $row = DB::table('auth_users_v2')->where('username', 'owner')->first();
        $this->assertSame('Aktif', $row->status);
        $this->assertSame('ROLE-OWNER_SUPER_ADMIN', $row->role_id);
        $this->assertTrue(password_verify('rahasia-123', $row->password_hash));
        $this->assertSame(1, DB::table('system_users')->count(), 'mirrored into the legacy table');
    }

    public function test_running_it_again_resets_the_password_without_duplicating(): void
    {
        Artisan::call('jago:create-owner', ['--password' => 'pertama-123', '--no-interaction' => true]);
        DB::table('auth_users_v2')->update(['status' => 'Nonaktif']);
        Artisan::call('jago:create-owner', ['--password' => 'kedua-12345', '--no-interaction' => true]);

        $this->assertSame(1, DB::table('auth_users_v2')->count());
        $row = DB::table('auth_users_v2')->first();
        $this->assertSame('Aktif', $row->status);
        $this->assertTrue(password_verify('kedua-12345', $row->password_hash));
        $this->assertSame(1, DB::table('system_users')->count());
    }

    public function test_it_needs_a_password_and_refuses_a_short_one_in_production(): void
    {
        $this->assertSame(1, Artisan::call('jago:create-owner', ['--no-interaction' => true]));
        $this->assertSame(0, DB::table('auth_users_v2')->count());

        $this->app['env'] = 'production';
        $this->assertSame(1, Artisan::call('jago:create-owner', ['--password' => 'pendek', '--no-interaction' => true]));
        $this->assertStringContainsString('minimal 8', Artisan::output());
    }
}
