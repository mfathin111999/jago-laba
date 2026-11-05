<?php

namespace Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /** Legacy table that grants a user a workspace (the plain shape most tests need). */
    protected function createWorkspaceAccessTable(): void
    {
        Schema::create('workspace_user_access', function (Blueprint $table): void {
            $table->string('user_key');
            $table->string('workspace');
        });
    }

    /** Legacy audit table written by AuditLogger. */
    protected function createAuditLogTable(): void
    {
        Schema::create('system_audit_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            foreach (['event_id', 'event_time', 'user_id', 'username', 'module', 'action', 'record_id', 'reference_id', 'before_json', 'after_json', 'status', 'notes'] as $column) {
                $table->text($column)->nullable();
            }
        });
    }
}
