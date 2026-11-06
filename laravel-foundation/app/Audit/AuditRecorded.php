<?php

namespace App\Audit;

/**
 * Something worth keeping a trail of happened (a sale posted, a store connected, an HPP changed). Dispatched by
 * AuditLogger::record(); the default listener writes it to system_audit_logs, and other listeners can react to it.
 */
final class AuditRecorded
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $username,
        public readonly string $module,
        public readonly string $action,
        public readonly ?string $recordId,
        public readonly ?string $referenceId,
        public readonly ?array $before,
        public readonly ?array $after,
        public readonly string $notes = '',
        public readonly string $status = 'SUCCESS',
    ) {}
}
