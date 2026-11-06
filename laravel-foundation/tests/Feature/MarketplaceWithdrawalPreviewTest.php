<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MarketplaceWithdrawalPreviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('jago.company_key', 'LOCAL-COMPANY');

        Schema::create('auth_users_v2', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('username')->unique();
            $table->string('name')->nullable();
            $table->text('password_hash');
            $table->string('role_id')->nullable();
            $table->string('status')->default('aktif');
            $table->timestamps();
        });
        Schema::create('workspace_user_access', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('user_key');
            $table->string('workspace');
        });
        Schema::create('marketplace_stores', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_key');
            $table->string('platform')->nullable();
            $table->string('store_name')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
        Schema::create('marketplace_withdrawals', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('company_key');
            $table->string('store_id');
            $table->string('external_id');
        });

        DB::table('auth_users_v2')->insert([
            'username' => 'owner',
            'name' => 'Owner Preview',
            'password_hash' => password_hash('owner-secret', PASSWORD_BCRYPT),
            'role_id' => 'ROLE-OWNER_SUPER_ADMIN',
            'status' => 'aktif',
        ]);
        DB::table('workspace_user_access')->insert([
            'user_key' => '1',
            'workspace' => 'marketplace',
        ]);
        DB::table('marketplace_stores')->insert([
            ['id' => 'store-local', 'company_key' => 'LOCAL-COMPANY', 'platform' => 'TikTok Shop', 'store_name' => 'Local', 'archived_at' => null],
            ['id' => 'store-foreign', 'company_key' => 'OTHER-COMPANY', 'platform' => 'TikTok Shop', 'store_name' => 'Foreign', 'archived_at' => null],
        ]);
    }

    public function test_xlsx_preview_preserves_amount_date_scope_and_does_not_insert_final_rows(): void
    {
        $this->loginAndSelectMarketplace();
        $path = $this->makeXlsxFixture();

        try {
            $response = $this->withHeader('X-CSRF-TOKEN', csrf_token())
                ->post('/pilot/marketplace/withdrawals/preview', [
                    'store_id' => 'store-local',
                    'report' => new UploadedFile($path, 'income-fixture.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', UPLOAD_ERR_OK, true),
                ]);

            $response->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('read_only', true)
                ->assertJsonPath('provider_call', false)
                ->assertJsonPath('write', false)
                ->assertJsonPath('data.count', 1)
                ->assertJsonPath('data.rows.0.external_id', '1234567890123456789')
                ->assertJsonPath('data.rows.0.amount', '1234.50')
                ->assertJsonPath('data.rows.0.requested_at', '2026-10-01 00:00:00')
                ->assertJsonPath('data.rows.0.source_timezone', 'UTC+8')
                ->assertJsonPath('data.rows.0.source_date_precision', 'date')
                ->assertHeader('Cache-Control', 'no-store, private');

            $this->assertSame(0, DB::table('marketplace_withdrawals')->count());
            $this->assertNotNull(session('marketplace.withdrawal_preview.key'));
            $this->assertSame('LOCAL-COMPANY', session('marketplace.withdrawal_preview.company_key'));
            $this->assertSame('store-local', session('marketplace.withdrawal_preview.store_id'));
        } finally {
            @unlink($path);
        }
    }

    public function test_foreign_store_is_rejected_before_file_preview(): void
    {
        $this->loginAndSelectMarketplace();
        $path = $this->makeXlsxFixture();

        try {
            $this->withHeader('X-CSRF-TOKEN', csrf_token())
                ->post('/pilot/marketplace/withdrawals/preview', [
                    'store_id' => 'store-foreign',
                    'report' => new UploadedFile($path, 'income-fixture.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', UPLOAD_ERR_OK, true),
                ])
                ->assertStatus(403)
                ->assertJsonPath('code', 'STORE_FORBIDDEN');

            $this->assertSame(0, DB::table('marketplace_withdrawals')->count());
        } finally {
            @unlink($path);
        }
    }

    private function loginAndSelectMarketplace(): void
    {
        $this->postJson('/api/rpc.php', [
            'method' => 'prosesLoginBackend',
            'args' => ['owner', 'owner-secret'],
        ])->assertOk()->assertJsonPath('result.status', 'success');

        $this->withHeader('X-CSRF-TOKEN', csrf_token())
            ->postJson('/api/workspace.php', ['workspace' => 'marketplace'])
            ->assertOk();
    }

    private function makeXlsxFixture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'jl-withdrawal-preview-');
        if ($path === false) {
            throw new \RuntimeException('Temporary XLSX path unavailable.');
        }

        unlink($path);
        $zipPath = $path.'.xlsx';
        $zip = new \PharData($zipPath, 0, null, \Phar::ZIP);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Riwayat penarikan" sheetId="1" r:id="rId1"/><sheet name="Laporan" sheetId="2" r:id="rId2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Target="worksheets/sheet2.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="18" uniqueCount="18"><si><t>Jenis transaksi</t></si><si><t>ID referensi</t></si><si><t>Waktu permintaan</t></si><si><t>Total</t></si><si><t>Status</t></si><si><t>Waktu keberhasilan</t></si><si><t>Rekening bank</t></si><si><t>Withdrawal</t></si><si><t>1234567890123456789</t></si><si><t>2026/10/01</t></si><si><t>-1234.50</t></si><si><t>Transferred</t></si><si><t>2026/10/02</t></si><si><t>1234567890</t></si><si><t>Mata uang</t></si><si><t>IDR</t></si><si><t>Zona waktu</t></si><si><t>UTC+8</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c><c r="E1" t="s"><v>4</v></c><c r="F1" t="s"><v>5</v></c><c r="G1" t="s"><v>6</v></c></row><row r="2"><c r="A2" t="s"><v>7</v></c><c r="B2" t="s"><v>8</v></c><c r="C2" t="s"><v>9</v></c><c r="D2" t="s"><v>10</v></c><c r="E2" t="s"><v>11</v></c><c r="F2" t="s"><v>12</v></c><c r="G2" t="s"><v>13</v></c></row></sheetData></worksheet>');
        $zip->addFromString('xl/worksheets/sheet2.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>14</v></c><c r="B1" t="s"><v>15</v></c></row><row r="2"><c r="A2" t="s"><v>16</v></c><c r="B2" t="s"><v>17</v></c></row></sheetData></worksheet>');
        unset($zip);

        return $zipPath;
    }
}
