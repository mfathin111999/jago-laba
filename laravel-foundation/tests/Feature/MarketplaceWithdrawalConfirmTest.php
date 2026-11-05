<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MarketplaceWithdrawalConfirmTest extends TestCase
{
    private const HEADER = "withdrawal_id,requested_at,completed_at,amount,fee,net_amount,currency,status,type,bank_name,account_number,failure_reason\n";

    protected function setUp(): void
    {
        parent::setUp();

        $this->createWorkspaceAccessTable();
        Schema::create('marketplace_stores', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_key');
            $table->string('platform')->nullable();
            $table->string('store_name')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
        Schema::create('marketplace_withdrawals', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('company_key', 120);
            $table->string('store_id', 120);
            $table->string('external_id', 120);
            $table->string('withdrawal_type', 30)->nullable();
            $table->dateTime('requested_at');
            $table->dateTime('completed_at')->nullable();
            $table->decimal('amount', 18, 2);
            $table->decimal('fee', 18, 2)->nullable();
            $table->decimal('net_amount', 18, 2)->nullable();
            $table->char('currency', 3);
            $table->string('source_status', 100);
            $table->string('normalized_status', 20);
            $table->string('bank_name', 100)->nullable();
            $table->string('account_mask', 30)->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->string('source_kind', 20)->default('import');
            $table->char('source_hash', 64);
            $table->char('row_hash', 64);
            $table->string('imported_by', 120);
            $table->dateTime('imported_at');
            $table->dateTime('updated_at');
            $table->unique(['company_key', 'store_id', 'external_id']);
        });
        Schema::create('marketplace_withdrawal_audit', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('company_key', 120);
            $table->string('store_id', 120);
            $table->string('external_id', 120);
            $table->string('actor', 120);
            $table->char('previous_hash', 64)->nullable();
            $table->char('new_hash', 64);
            $table->char('source_hash', 64);
            $table->dateTime('created_at');
        });

        DB::table('marketplace_stores')->insert([
            ['id' => 'a', 'company_key' => 'A', 'platform' => 'Shopee', 'store_name' => 'Toko A', 'archived_at' => null],
            ['id' => 'b', 'company_key' => 'B', 'platform' => 'Shopee', 'store_name' => 'Toko B', 'archived_at' => null],
        ]);
        DB::table('workspace_user_access')->insert([['user_key' => 'owner', 'workspace' => 'marketplace']]);
        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');
    }

    private function loginAs(string $role): void
    {
        $this->actingAs(new LegacyUser(['id' => 'owner', 'role_id' => $role, 'company_key' => 'A']), 'web');
        session(['_token' => 'tok', 'workspace' => 'marketplace']);
    }

    /** @param list<array{string, string}> $rows [id, amount] */
    private function csv(array $rows): UploadedFile
    {
        $body = self::HEADER;
        foreach ($rows as [$id, $amount]) {
            $body .= "$id,2026-10-01,2026-10-02,$amount,0,$amount,IDR,PAID,BANK,BCA,1234567890,\n";
        }

        return UploadedFile::fake()->createWithContent('penarikan.csv', $body);
    }

    /** @param array<string, mixed> $payload */
    private function legacy(array $payload): TestResponse
    {
        return $this->withHeaders(['X-CSRF-TOKEN' => 'tok', 'Accept' => 'application/json'])->post('/api/marketplace-withdrawals.php', $payload);
    }

    /** @param list<array{string, string}> $rows */
    private function stage(array $rows, string $store = 'a'): string
    {
        return (string) $this->legacy(['action' => 'preview', 'store_id' => $store, 'report' => $this->csv($rows)])
            ->assertOk()->json('data.key');
    }

    public function test_template_downloads_the_csv_format(): void
    {
        $response = $this->get('/api/marketplace-withdrawals.php?action=template')->assertOk();

        $this->assertStringContainsString('attachment; filename="format-penarikan.csv"', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('withdrawal_id,requested_at,completed_at,amount', $response->getContent());
    }

    public function test_confirm_imports_every_staged_row_not_only_the_displayed_sample(): void
    {
        $rows = array_map(fn (int $i) => ['WD-'.$i, (string) (1000 * $i)], range(1, 12));
        $key = $this->stage($rows);

        $this->assertSame(0, DB::table('marketplace_withdrawals')->count());
        $this->assertCount(12, session('marketplace.withdrawal_preview.rows'));

        $this->legacy(['action' => 'confirm', 'key' => $key])
            ->assertOk()
            ->assertJsonPath('data.inserted', 12)
            ->assertJsonPath('data.updated', 0);

        $this->assertSame(12, DB::table('marketplace_withdrawals')->where('company_key', 'A')->where('store_id', 'a')->count());
        $this->assertSame(12, DB::table('marketplace_withdrawal_audit')->count());
        $this->assertNull(session('marketplace.withdrawal_preview'));
        $this->assertSame('succeeded', DB::table('marketplace_withdrawals')->where('external_id', 'WD-1')->value('normalized_status'));
        $this->assertSame('•••• 7890', DB::table('marketplace_withdrawals')->where('external_id', 'WD-1')->value('account_mask'));
    }

    public function test_reimport_is_idempotent_and_changes_are_audited_with_the_previous_hash(): void
    {
        $this->legacy(['action' => 'confirm', 'key' => $this->stage([['WD-1', '1000'], ['WD-2', '2000']])])->assertOk();
        $original = DB::table('marketplace_withdrawals')->where('external_id', 'WD-2')->value('row_hash');

        $this->legacy(['action' => 'confirm', 'key' => $this->stage([['WD-1', '1000'], ['WD-2', '2000']])])
            ->assertOk()->assertJsonPath('data.unchanged', 2)->assertJsonPath('data.inserted', 0);
        $this->assertSame(2, DB::table('marketplace_withdrawal_audit')->count());

        $this->legacy(['action' => 'confirm', 'key' => $this->stage([['WD-1', '1000'], ['WD-2', '2500']])])
            ->assertOk()->assertJsonPath('data.unchanged', 1)->assertJsonPath('data.updated', 1);

        $this->assertSame(2, DB::table('marketplace_withdrawals')->count());
        $this->assertEquals(2500, DB::table('marketplace_withdrawals')->where('external_id', 'WD-2')->value('amount'));
        $audit = DB::table('marketplace_withdrawal_audit')->where('external_id', 'WD-2')->orderByDesc('id')->first();
        $this->assertSame($original, $audit->previous_hash);
        $this->assertNotSame($original, $audit->new_hash);
    }

    public function test_confirm_rejects_wrong_or_expired_preview_and_writes_nothing(): void
    {
        $key = $this->stage([['WD-1', '1000']]);

        $this->legacy(['action' => 'confirm', 'key' => 'salah'])
            ->assertForbidden()->assertJsonPath('message', 'Pratinjau kedaluwarsa. Unggah kembali laporan.');

        $stage = session('marketplace.withdrawal_preview');
        session(['marketplace.withdrawal_preview' => ['expires_at' => time() - 1] + $stage]);
        $this->legacy(['action' => 'confirm', 'key' => $key])->assertForbidden();

        session()->forget('marketplace.withdrawal_preview');
        $this->legacy(['action' => 'confirm', 'key' => $key])->assertForbidden();
        $this->assertSame(0, DB::table('marketplace_withdrawals')->count());
    }

    public function test_foreign_company_cannot_reuse_a_staged_import_and_foreign_store_is_rejected(): void
    {
        $key = $this->stage([['WD-1', '1000']]);
        $this->actingAs(new LegacyUser(['id' => 'owner', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN', 'company_key' => 'B']), 'web');

        $this->legacy(['action' => 'confirm', 'key' => $key])->assertForbidden();
        $this->assertSame(0, DB::table('marketplace_withdrawals')->count());

        $this->loginAs('ROLE-OWNER_SUPER_ADMIN');
        $this->legacy(['action' => 'preview', 'store_id' => 'b', 'report' => $this->csv([['WD-9', '1']])])->assertForbidden();
    }

    public function test_csrf_method_and_role_are_enforced(): void
    {
        $key = $this->stage([['WD-1', '1000']]);

        $this->withHeaders(['X-CSRF-TOKEN' => 'wrong', 'Accept' => 'application/json'])
            ->post('/api/marketplace-withdrawals.php', ['action' => 'confirm', 'key' => $key])->assertStatus(419);
        $this->getJson('/api/marketplace-withdrawals.php?action=confirm')->assertForbidden();

        $this->loginAs('ROLE-PEMBACA');
        $this->legacy(['action' => 'confirm', 'key' => $key])->assertForbidden();
        $this->get('/api/marketplace-withdrawals.php?action=template')->assertForbidden();
        $this->assertSame(0, DB::table('marketplace_withdrawals')->count());
    }

    public function test_invalid_csv_row_is_rejected_at_preview_without_staging(): void
    {
        $bad = UploadedFile::fake()->createWithContent('bad.csv', self::HEADER."WD-1,2026-10-01,,\"1.000,50\",,,IDR,PAID,,,,\n");

        $this->legacy(['action' => 'preview', 'store_id' => 'a', 'report' => $bad])->assertStatus(422);
        $this->assertNull(session('marketplace.withdrawal_preview'));
    }
}
