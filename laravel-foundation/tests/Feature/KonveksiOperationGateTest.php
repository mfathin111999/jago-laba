<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use App\Pilots\Konveksi\KonveksiPilotReadService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class KonveksiOperationGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('workspace_user_access', function (Blueprint $table): void {
            $table->string('user_key');
            $table->string('workspace');
        });
        Schema::create('system_audit_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            foreach (['event_id', 'event_time', 'user_id', 'username', 'module', 'action', 'record_id', 'reference_id', 'before_json', 'after_json', 'status', 'notes'] as $column) {
                $table->text($column)->nullable();
            }
        });
        Schema::create('system_settings', function (Blueprint $table): void {
            $table->string('setting_key')->primary();
            $table->text('setting_value')->nullable();
        });
        Schema::create('konveksi_request_receipts', function (Blueprint $table): void {
            $table->string('request_key', 190)->primary();
            $table->string('company_key', 120);
            $table->char('request_hash', 64);
            $table->longText('response_json');
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('system_table_map', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('sheet_name');
            $table->string('api_key');
            $table->string('table_name');
        });
        foreach (['hutang_konveksi', 'riwayat_bayar_hutang_konveksi', 'keuangan', 'produk'] as $key) {
            $name = 'jl_data_'.$key;
            Schema::create($name, function (Blueprint $table): void {
                $table->bigIncrements('_row_id');
                $table->unsignedInteger('_row_order')->default(0);
                $table->longText('_data_json');
            });
            DB::table('system_table_map')->insert(['sheet_name' => $key, 'api_key' => $key, 'table_name' => $name]);
        }
        DB::table('jl_data_produk')->insert([['_row_order' => 0, '_data_json' => json_encode(['id' => 'P1', 'nama' => 'Kemeja'])]]);
        DB::table('workspace_user_access')->insert([['user_key' => 'u1', 'workspace' => 'konveksi']]);

        $this->actingAs(new LegacyUser(['id' => 'u1', 'username' => 'u1', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN', 'company_key' => 'LOCAL-COMPANY']), 'web');
        session(['_token' => 'tok', 'workspace' => 'konveksi']);
    }

    /** @param array<string, string> $headers */
    private function rpc(string $method, array $args, array $headers = []): TestResponse
    {
        return $this->withHeaders(['X-CSRF-TOKEN' => 'tok'] + $headers)->postJson('/api/rpc.php', ['method' => $method, 'args' => $args]);
    }

    private function key(string $suffix = 'a'): array
    {
        return ['X-Idempotency-Key' => 'test-key-0123456789-'.$suffix];
    }

    /** @return array<string, mixed> */
    private function debt(array $overrides = []): array
    {
        return $overrides + ['id_pegawai' => 'PGW-1', 'nama_pegawai' => 'Budi', 'nominal' => 100000, 'divisi' => 'jahit', 'tanggal' => '2026-11-01'];
    }

    public function test_a_write_without_an_idempotency_key_is_refused_and_changes_nothing(): void
    {
        $this->rpc('createHutangKonveksiManual', [$this->debt()])
            ->assertStatus(422)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
        $this->rpc('createHutangKonveksiManual', [$this->debt()], ['X-Idempotency-Key' => 'short'])->assertStatus(422);

        $this->assertSame(0, DB::table('jl_data_hutang_konveksi')->count());
        $this->assertSame(0, DB::table('konveksi_request_receipts')->count());
    }

    public function test_a_repeated_request_replays_the_stored_response_instead_of_writing_twice(): void
    {
        $first = $this->rpc('createHutangKonveksiManual', [$this->debt()], $this->key())->assertOk();
        $second = $this->rpc('createHutangKonveksiManual', [$this->debt()], $this->key())->assertOk();

        $this->assertSame(1, DB::table('jl_data_hutang_konveksi')->count(), 'a double click must create one debt');
        $this->assertSame($first->json('result.data.id_hutang'), $second->json('result.data.id_hutang'));
        $this->assertSame(1, DB::table('konveksi_request_receipts')->count());
        $this->assertSame(1, DB::table('system_audit_logs')->where('action', 'CREATE_HUTANG_KONVEKSI')->count());
        $this->assertArrayHasKey('versions', $first->json('result.__meta'));
    }

    public function test_reusing_a_key_for_different_content_is_refused(): void
    {
        $this->rpc('createHutangKonveksiManual', [$this->debt()], $this->key())->assertOk();

        $this->rpc('createHutangKonveksiManual', [$this->debt(['nominal' => 999999])], $this->key())
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertSame(1, DB::table('jl_data_hutang_konveksi')->count());
        $this->rpc('createHutangKonveksiManual', [$this->debt()], $this->key('b'))->assertOk();
        $this->assertSame(2, DB::table('jl_data_hutang_konveksi')->count(), 'a new key is a genuinely new request');
    }

    public function test_paying_twice_with_the_same_key_pays_once(): void
    {
        $this->rpc('createHutangKonveksiManual', [$this->debt()], $this->key('debt'))->assertOk();
        $payment = ['id_pegawai' => 'PGW-1', 'nominal_bayar' => 60000, 'id_rekening' => 'KAS-1', 'tanggal_bayar' => '2026-11-02'];

        $this->rpc('bayarHutangKonveksi', [$payment], $this->key('pay'))->assertOk()->assertJsonPath('result.data.sisa_saldo', 40000);
        $this->rpc('bayarHutangKonveksi', [$payment], $this->key('pay'))->assertOk()->assertJsonPath('result.data.sisa_saldo', 40000);

        $debt = json_decode(DB::table('jl_data_hutang_konveksi')->value('_data_json'), true);
        $this->assertEquals(40000, $debt['saldo_hutang']);
        $this->assertEquals(60000, $debt['total_dibayar']);
        $this->assertSame(1, DB::table('jl_data_riwayat_bayar_hutang_konveksi')->count());
        $this->assertSame(1, DB::table('jl_data_keuangan')->count(), 'cash must leave the account only once');
    }

    public function test_a_failed_operation_leaves_no_receipt_so_it_can_be_retried(): void
    {
        $response = $this->rpc('bayarHutangKonveksi', [['id_pegawai' => 'PGW-1', 'nominal_bayar' => 5, 'id_rekening' => 'KAS-1']], $this->key('fail'));

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertSame(0, DB::table('konveksi_request_receipts')->count());
    }

    public function test_sync_tab_is_refused_when_the_client_data_is_stale_or_unversioned(): void
    {
        $rows = [['id' => 'P1', 'nama' => 'Dari tab lama']];
        $versions = app(KonveksiPilotReadService::class)->collectionVersions(['produk']);

        $this->rpc('syncTab', ['produk', json_encode($rows)], $this->key('s1'))
            ->assertStatus(409)->assertJsonPath('error.code', 'COLLECTION_STALE');
        $this->rpc('syncTab', ['produk', json_encode($rows)], $this->key('s2') + ['X-JL-Collection-Versions' => json_encode(['produk' => str_repeat('0', 64)])])
            ->assertStatus(409);
        $this->assertSame('Kemeja', json_decode(DB::table('jl_data_produk')->value('_data_json'), true)['nama'], 'stale write must not overwrite');

        $this->rpc('syncTab', ['produk', json_encode($rows)], $this->key('s3') + ['X-JL-Collection-Versions' => json_encode($versions)])->assertOk();
        $this->assertSame('Dari tab lama', json_decode(DB::table('jl_data_produk')->value('_data_json'), true)['nama']);

        // The same screen saving again without reloading now holds an outdated version.
        $this->rpc('syncTab', ['produk', json_encode([['id' => 'P1', 'nama' => 'Tulis lagi']])], $this->key('s4') + ['X-JL-Collection-Versions' => json_encode($versions)])
            ->assertStatus(409);
    }

    public function test_sync_multi_tabs_checks_every_named_collection(): void
    {
        $this->rpc('syncMultiTabs', [json_encode(['produk' => [['id' => 'X']]])], $this->key('m1'))->assertStatus(409);
        $versions = app(KonveksiPilotReadService::class)->collectionVersions(['produk']);
        $this->rpc('syncMultiTabs', [json_encode(['produk' => [['id' => 'X']]])], $this->key('m2') + ['X-JL-Collection-Versions' => json_encode($versions)])->assertOk();
    }

    public function test_writes_after_a_data_reset_need_the_current_epoch(): void
    {
        DB::table('system_settings')->insert(['setting_key' => 'KONVEKSI_RESET_EPOCH', 'setting_value' => 'epoch-7']);

        $this->rpc('createHutangKonveksiManual', [$this->debt()], $this->key('e1'))->assertStatus(409)->assertJsonPath('error.code', 'RESET_EPOCH_MISMATCH');
        $this->rpc('createHutangKonveksiManual', [$this->debt()], $this->key('e2') + ['X-JL-Reset-Epoch' => 'epoch-6'])->assertStatus(409);
        $this->assertSame(0, DB::table('jl_data_hutang_konveksi')->count());

        $this->rpc('createHutangKonveksiManual', [$this->debt()], $this->key('e3') + ['X-JL-Reset-Epoch' => 'epoch-7'])->assertOk();
    }

    public function test_writes_are_refused_when_the_receipts_table_is_missing(): void
    {
        Schema::drop('konveksi_request_receipts');

        $response = $this->rpc('createHutangKonveksiManual', [$this->debt()], $this->key());

        $this->assertGreaterThanOrEqual(500, $response->getStatusCode());
        $this->assertSame(0, DB::table('jl_data_hutang_konveksi')->count(), 'never run a write unprotected');
    }

    public function test_reads_and_non_gated_methods_do_not_need_a_key(): void
    {
        $this->rpc('loadSemuaData', [])->assertOk();
        $this->rpc('getGeminiApiKeyStatusServerRpc', [])->assertOk();
        $this->rpc('getRiwayatBackupJagoLaba', [])->assertOk();
    }
}
