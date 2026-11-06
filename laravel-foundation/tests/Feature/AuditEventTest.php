<?php

namespace Tests\Feature;

use App\Audit\AuditLogger;
use App\Audit\AuditRecorded;
use App\Auth\LegacyUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class AuditEventTest extends TestCase
{
    private function user(): LegacyUser
    {
        return new LegacyUser(['id' => 'u1', 'username' => 'kasir', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN']);
    }

    public function test_recording_dispatches_an_event_other_listeners_can_react_to(): void
    {
        Event::fake([AuditRecorded::class]);

        app(AuditLogger::class)->record($this->user(), 'Konveksi', 'POST_SALE', 'S1', 'REF1', null, ['total' => 5], 'catatan');

        Event::assertDispatched(AuditRecorded::class, fn (AuditRecorded $e): bool => $e->module === 'Konveksi' && $e->action === 'POST_SALE' && $e->username === 'kasir' && $e->after === ['total' => 5]);
    }

    public function test_the_default_listener_stores_the_event(): void
    {
        $this->createAuditLogTable();

        app(AuditLogger::class)->record($this->user(), 'Marketplace', 'UPDATE_SKU_HPP', 'SKU-1', 'STR-1', ['hpp' => 1], ['hpp' => 2], 'ubah');

        $row = DB::table('system_audit_logs')->first();
        $this->assertSame('UPDATE_SKU_HPP', $row->action);
        $this->assertSame('kasir', $row->username);
        $this->assertSame('{"hpp":2}', $row->after_json);
        $this->assertSame('SUCCESS', $row->status);
        $this->assertMatchesRegularExpression('/^AUD-\d{14}-[A-F0-9]{6}$/', $row->event_id);
    }

    public function test_an_action_that_cannot_be_traced_is_stopped(): void
    {
        Schema::dropIfExists('system_audit_logs');

        $this->expectException(RuntimeException::class);
        app(AuditLogger::class)->record($this->user(), 'Konveksi', 'X', null, null, null, null);
    }
}
