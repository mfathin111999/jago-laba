<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use App\Authorization\WorkspaceContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class WorkspaceGrantsTest extends TestCase
{
    private function user(string $role = 'ROLE-STAF'): LegacyUser
    {
        return new LegacyUser(['id' => 'u1', 'username' => 'u1', 'role_id' => $role, 'company_key' => 'A']);
    }

    public function test_the_reviewed_legacy_table_shape_is_honoured_including_revocations(): void
    {
        Schema::create('workspace_user_access', function (Blueprint $table): void {
            $table->string('user_key');
            $table->string('workspace_code');
            $table->boolean('is_allowed')->default(true);
        });
        \DB::table('workspace_user_access')->insert([
            ['user_key' => 'u1', 'workspace_code' => 'marketplace', 'is_allowed' => 1],
            ['user_key' => 'u1', 'workspace_code' => 'konveksi', 'is_allowed' => 0],
        ]);

        $this->assertSame(['marketplace'], app(WorkspaceContext::class)->allowed($this->user()), 'a revoked row must not grant access, and a grant must not be ignored');
    }

    public function test_the_simple_table_shape_still_works_and_no_table_means_role_defaults(): void
    {
        $this->assertSame(['konveksi'], app(WorkspaceContext::class)->allowed($this->user()));
        $this->assertSame(['konveksi', 'marketplace'], app(WorkspaceContext::class)->allowed($this->user('ROLE-OWNER_SUPER_ADMIN')));

        Schema::create('workspace_user_access', function (Blueprint $table): void {
            $table->string('user_key');
            $table->string('workspace');
        });
        \DB::table('workspace_user_access')->insert([['user_key' => 'u1', 'workspace' => 'marketplace']]);

        $this->assertSame(['marketplace'], app(WorkspaceContext::class)->allowed($this->user()));
    }
}
