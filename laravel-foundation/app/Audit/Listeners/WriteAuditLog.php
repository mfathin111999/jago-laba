<?php

namespace App\Audit\Listeners;

use App\Audit\AuditRecorded;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Stores an audit event in system_audit_logs. It runs synchronously inside the caller's transaction, and a missing audit
 * table stops the operation: an action that cannot be traced must not happen.
 */
final class WriteAuditLog
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function handle(AuditRecorded $event): void
    {
        if (! Schema::hasTable('system_audit_logs')) {
            throw new RuntimeException('Audit table belum tersedia.');
        }

        $this->database->connection()->table('system_audit_logs')->insert([
            'event_id' => 'AUD-'.now()->format('YmdHis').'-'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 6)),
            'event_time' => now(),
            'user_id' => $event->userId,
            'username' => $event->username,
            'module' => $event->module,
            'action' => $event->action,
            'record_id' => $event->recordId,
            'reference_id' => $event->referenceId,
            'before_json' => $event->before === null ? null : json_encode($event->before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'after_json' => $event->after === null ? null : json_encode($event->after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'status' => $event->status,
            'notes' => $event->notes,
        ]);
    }
}
