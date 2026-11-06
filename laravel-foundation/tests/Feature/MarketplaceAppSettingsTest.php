<?php

namespace Tests\Feature;

use App\Auth\AuthenticatedUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MarketplaceAppSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createWorkspaceAccessTable();
        $this->createAuditLogTable();
        Schema::create('marketplace_app_settings', function (Blueprint $table): void {
            $table->string('setting_key', 100)->primary();
            $table->text('setting_value')->nullable();
            $table->string('setting_group', 50)->default('financial');
            $table->dateTime('updated_at')->nullable();
        });
        DB::table('marketplace_app_settings')->insert([
            ['setting_key' => 'ppn_tax_rate', 'setting_value' => '11.0', 'setting_group' => 'marketing'],
            ['setting_key' => 'cron_order_sync_interval_min', 'setting_value' => '15', 'setting_group' => 'sync'],
            ['setting_key' => 'webhook_global_secret', 'setting_value' => 'OLD-SECRET-VALUE', 'setting_group' => 'sync'],
        ]);
        DB::table('workspace_user_access')->insert([['user_key' => 'u1', 'workspace' => 'marketplace']]);
        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');
    }

    private function loginAs(string $role): void
    {
        $this->actingAs(new AuthenticatedUser(['id' => 'u1', 'username' => 'admin', 'role_id' => $role, 'company_key' => 'A']), 'web');
        session(['_token' => 'tok', 'workspace' => 'marketplace']);
    }

    /** @param array<string, mixed> $payload */
    private function save(array $payload): TestResponse
    {
        return $this->withHeader('X-CSRF-TOKEN', 'tok')->postJson('/api/marketplace-ajax.php?action=save_app_settings', $payload);
    }

    public function test_known_settings_are_updated_and_unknown_keys_are_ignored(): void
    {
        $this->save(['ppn_tax_rate' => '12', 'tidak_ada' => 'x', '_token' => 'tok'])
            ->assertOk()->assertJsonPath('updated_count', 1);

        $this->assertSame('12', DB::table('marketplace_app_settings')->where('setting_key', 'ppn_tax_rate')->value('setting_value'));
        $this->assertSame('15', DB::table('marketplace_app_settings')->where('setting_key', 'cron_order_sync_interval_min')->value('setting_value'));
        $this->assertSame(3, DB::table('marketplace_app_settings')->count(), 'no key can be created through this endpoint');
    }

    public function test_the_audit_trail_records_what_changed_without_exposing_secrets(): void
    {
        $this->save(['ppn_tax_rate' => '12', 'cron_order_sync_interval_min' => '15', 'webhook_global_secret' => 'NEW-SECRET-VALUE'])->assertOk();

        $this->assertSame('NEW-SECRET-VALUE', DB::table('marketplace_app_settings')->where('setting_key', 'webhook_global_secret')->value('setting_value'));

        $log = DB::table('system_audit_logs')->where('action', 'SAVE_APP_SETTINGS')->first();
        $trail = $log->before_json.$log->after_json.$log->notes;
        $this->assertStringNotContainsString('NEW-SECRET-VALUE', $trail);
        $this->assertStringNotContainsString('OLD-SECRET-VALUE', $trail);

        $after = json_decode($log->after_json, true);
        $before = json_decode($log->before_json, true);
        $this->assertSame('12', $after['ppn_tax_rate']);
        $this->assertSame('11.0', $before['ppn_tax_rate']);
        $this->assertArrayHasKey('webhook_global_secret', $after, 'the change is recorded, only the values are masked');
        $this->assertArrayNotHasKey('cron_order_sync_interval_min', $after, 'unchanged settings are not logged');
    }

    public function test_only_administrators_may_change_settings(): void
    {
        $this->loginAs('ROLE-KEUANGAN');
        $this->save(['ppn_tax_rate' => '99'])->assertForbidden();
        $this->assertSame('11.0', DB::table('marketplace_app_settings')->where('setting_key', 'ppn_tax_rate')->value('setting_value'));
    }
}
