<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use App\Pilots\Konveksi\KonveksiDocumentAndAiService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class KonveksiGeminiTest extends TestCase
{
    private const KEY = 'AIzaSyEXAMPLE-0123456789-abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('system_audit_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            foreach (['event_id', 'event_time', 'user_id', 'username', 'module', 'action', 'record_id', 'reference_id', 'before_json', 'after_json', 'status', 'notes'] as $column) {
                $table->text($column)->nullable();
            }
        });
        Schema::create('system_settings', function (Blueprint $table): void {
            $table->string('setting_key')->primary();
            $table->text('setting_value')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    private function service(): KonveksiDocumentAndAiService
    {
        return app(KonveksiDocumentAndAiService::class);
    }

    private function user(): LegacyUser
    {
        return new LegacyUser(['id' => 'u1', 'username' => 'admin', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN']);
    }

    public function test_the_key_is_stored_reported_masked_and_kept_out_of_the_audit_trail(): void
    {
        $this->assertFalse($this->service()->geminiStatus()['configured']);

        $this->service()->saveGeminiKey($this->user(), '  '.self::KEY.'  ');
        $this->service()->saveGeminiKey($this->user(), self::KEY);

        $this->assertSame(1, DB::table('system_settings')->count());
        $status = $this->service()->geminiStatus();
        $this->assertTrue($status['configured']);
        $this->assertSame('AIza••••cdef', $status['maskedKey']);
        $this->assertStringNotContainsString(self::KEY, json_encode($status));

        $log = DB::table('system_audit_logs')->first();
        $this->assertStringNotContainsString(self::KEY, json_encode($log));
    }

    public function test_saving_fails_loudly_when_it_cannot_store_the_key(): void
    {
        try {
            $this->service()->saveGeminiKey($this->user(), '   ');
            $this->fail('An empty key must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertSame('API key kosong.', $exception->getMessage());
        }

        Schema::drop('system_settings');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('API key tidak tersimpan');
        $this->service()->saveGeminiKey($this->user(), self::KEY);
    }

    public function test_invoice_reading_never_invents_a_result(): void
    {
        $missing = $this->service()->extractInvoice(['file_invoice' => ['base64' => 'x']]);
        $this->assertSame('GEMINI_KEY_MISSING', $missing['code']);

        $this->service()->saveGeminiKey($this->user(), self::KEY);
        $result = $this->service()->extractInvoice(['file_invoice' => ['base64' => 'x']]);

        $this->assertSame('error', $result['status']);
        $this->assertSame('PROVIDER_CONNECTOR_INACTIVE', $result['code']);
        $this->assertArrayNotHasKey('data', $result);
        $this->assertStringNotContainsString('Supplier OCR', json_encode($result));
    }
}
