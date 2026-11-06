<?php

namespace App\Audit;

use App\Auth\LegacyUser;
use Illuminate\Contracts\Events\Dispatcher;

/** The one place that turns "this user did that" into an AuditRecorded event. */
final class AuditLogger
{
    public function __construct(private readonly Dispatcher $events) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        LegacyUser $user,
        string $module,
        string $action,
        ?string $recordId,
        ?string $referenceId,
        ?array $before,
        ?array $after,
        string $notes = '',
    ): void {
        $userId = $user->getSource() === 'auth_users_v2'
            ? 'AUTHV2-'.$user->getLegacyUserId()
            : $user->getLegacyUserId();

        $this->events->dispatch(new AuditRecorded($userId, $user->getUsername(), $module, $action, $recordId, $referenceId, $before, $after, $notes));
    }
}
