<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MarketplaceBrandingTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('workspace_user_access', function (Blueprint $table): void {
            $table->string('user_key');
            $table->string('workspace');
        });
        Schema::create('marketplace_branding', function (Blueprint $table): void {
            $table->string('company_key', 120)->primary();
            $table->binary('logo_blob')->nullable();
            $table->string('logo_mime', 40)->nullable();
            $table->smallInteger('logo_size')->default(32);
            $table->string('logo_fit', 12)->default('contain');
            $table->string('logo_hash', 64)->nullable();
            $table->string('updated_by', 120);
            $table->dateTime('updated_at');
        });
        DB::table('workspace_user_access')->insert([['user_key' => 'owner', 'workspace' => 'marketplace']]);
        $this->loginAs('ROLE-OWNER_SUPER_ADMIN', 'A');
    }

    private function loginAs(string $role, string $company): void
    {
        $this->actingAs(new LegacyUser(['id' => 'owner', 'role_id' => $role, 'company_key' => $company]), 'web');
        session(['_token' => 'tok', 'workspace' => 'marketplace']);
    }

    /** @param array<string, mixed> $payload */
    private function save(array $payload): TestResponse
    {
        return $this->withHeaders(['X-CSRF-TOKEN' => 'tok', 'Accept' => 'application/json'])->post('/api/marketplace-branding.php', $payload);
    }

    private function logo(string $content = '', string $name = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content === '' ? base64_decode(self::PNG) : $content);
    }

    public function test_logo_is_saved_served_and_scoped_to_the_company(): void
    {
        $this->getJson('/api/marketplace-branding.php?image=1')->assertNotFound();

        $this->save(['logo' => $this->logo(), 'logo_size' => 44, 'logo_fit' => 'cover'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('branding.logo_mime', 'image/png')
            ->assertJsonPath('branding.logo_size', 32)
            ->assertJsonPath('branding.logo_fit', 'cover')
            ->assertJsonMissingPath('branding.logo_blob');

        $response = $this->get('/api/marketplace-branding.php?image=1')->assertOk();
        $this->assertSame(base64_decode(self::PNG), $response->getContent());
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        $this->assertSame(hash('sha256', base64_decode(self::PNG)), DB::table('marketplace_branding')->where('company_key', 'A')->value('logo_hash'));

        $this->loginAs('ROLE-OWNER_SUPER_ADMIN', 'B');
        $this->get('/api/marketplace-branding.php?image=1')->assertNotFound();
    }

    public function test_options_can_change_without_a_new_file_and_reset_removes_the_logo(): void
    {
        $this->save(['logo' => $this->logo(), 'logo_size' => 28, 'logo_fit' => 'contain'])->assertOk();
        $this->save(['logo_size' => 36, 'logo_fit' => 'cover'])->assertOk()
            ->assertJsonPath('branding.logo_size', 36)->assertJsonPath('branding.logo_mime', 'image/png');
        $this->get('/api/marketplace-branding.php?image=1')->assertOk();

        $this->save(['logo_size' => 32, 'logo_fit' => 'contain', 'reset_logo' => '1'])->assertOk()
            ->assertJsonPath('branding.logo_mime', null)->assertJsonPath('branding.logo_hash', null);
        $this->get('/api/marketplace-branding.php?image=1')->assertNotFound();
        $this->assertSame(1, DB::table('marketplace_branding')->count());
    }

    public function test_invalid_files_and_options_are_rejected_without_changes(): void
    {
        $this->save(['logo' => $this->logo('<?php echo 1; ?> not an image at all', 'evil.png'), 'logo_size' => 32, 'logo_fit' => 'contain'])
            ->assertStatus(422)->assertJsonPath('message', 'Format logo harus PNG, JPG, atau WebP yang valid.');
        $this->save(['logo' => $this->logo('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>', 'x.svg'), 'logo_size' => 32, 'logo_fit' => 'contain'])
            ->assertStatus(422);
        $this->save(['logo' => $this->logo(), 'logo_size' => 99, 'logo_fit' => 'contain'])->assertStatus(422);
        $this->save(['logo' => $this->logo(), 'logo_size' => 32, 'logo_fit' => 'stretch'])->assertStatus(422);

        $this->assertSame(0, DB::table('marketplace_branding')->count());
    }

    public function test_only_admins_with_csrf_can_save_but_any_marketplace_user_can_view(): void
    {
        $this->withHeaders(['X-CSRF-TOKEN' => 'wrong', 'Accept' => 'application/json'])
            ->post('/api/marketplace-branding.php', ['logo' => $this->logo(), 'logo_size' => 32, 'logo_fit' => 'contain'])->assertStatus(419);

        $this->save(['logo' => $this->logo(), 'logo_size' => 32, 'logo_fit' => 'contain'])->assertOk();

        $this->loginAs('ROLE-KEUANGAN', 'A');
        $this->save(['logo' => $this->logo(), 'logo_size' => 32, 'logo_fit' => 'contain', 'reset_logo' => '1'])->assertForbidden();
        $this->get('/api/marketplace-branding.php?image=1')->assertOk();
        $this->assertNotNull(DB::table('marketplace_branding')->where('company_key', 'A')->value('logo_hash'));

        $this->getJson('/api/marketplace-branding.php')->assertForbidden();
    }
}
