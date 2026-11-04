<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use App\Http\Controllers\KonveksiLegacyAppController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class KonveksiLegacyAppTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('workspace_user_access', function (Blueprint $table): void {
            $table->string('user_key');
            $table->string('workspace');
        });
        DB::table('workspace_user_access')->insert([['user_key' => 'u1', 'workspace' => 'konveksi']]);
    }

    private function login(string $workspace = 'konveksi'): void
    {
        $this->actingAs(new LegacyUser(['id' => 'u1', 'username' => 'u1', 'name' => 'Bu </script>Rina', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN', 'company_key' => 'A']), 'web');
        session(['_token' => 'csrf-token-1', 'workspace' => $workspace]);
    }

    public function test_the_konveksi_client_is_assembled_with_the_laravel_session_and_csrf_token(): void
    {
        $this->login();

        $response = $this->get('/index.php')->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('Jago Laba · Manajemen Konveksi', $html);
        $this->assertStringContainsString('"csrf":"csrf-token-1"', $html);
        $this->assertStringContainsString('"workspace":"konveksi"', $html);
        $this->assertStringContainsString('assets/konveksi/js/jl-api.js?v=', $html);
        $this->assertStringContainsString('assets/konveksi/css/konveksi-operations.css', $html);
        $this->assertGreaterThan(1_000_000, strlen($html), 'every legacy view must be part of the page');
        $this->assertLessThan(strpos($html, 'jl-api.js'), strpos($html, 'window.JL_SESSION'), 'the session must exist before the API client loads');
    }

    public function test_the_user_name_cannot_break_out_of_the_inline_script(): void
    {
        $this->login();

        $html = $this->get('/index.php')->getContent();

        $this->assertStringNotContainsString('Bu </script>Rina', $html);
        $this->assertStringContainsString(json_encode('Bu </script>Rina', JSON_HEX_TAG), $html);
    }

    public function test_the_client_assets_it_references_are_shipped_with_laravel(): void
    {
        foreach (['css/konveksi-operations.css', 'js/jl-api.js'] as $asset) {
            $this->assertFileExists(public_path('assets/konveksi/'.$asset));
        }
        $this->assertStringContainsString('/api/rpc.php', (string) file_get_contents(public_path('assets/konveksi/js/jl-api.js')));
    }

    public function test_the_view_list_matches_the_legacy_module(): void
    {
        $module = (string) file_get_contents(dirname(__DIR__, 3).'/app/Modules/Konveksi/Module.php');
        preg_match_all("/'(\d\d_[A-Za-z_]+)'/", substr($module, (int) strpos($module, 'function viewNames')), $match);

        $this->assertSame($match[1], KonveksiLegacyAppController::VIEWS);
        foreach (KonveksiLegacyAppController::VIEWS as $view) {
            $this->assertFileExists(config('jago.konveksi.views_path').'/'.$view.'.html');
        }
    }

    public function test_other_workspaces_and_guests_do_not_get_the_client(): void
    {
        $this->get('/index.php')->assertRedirect();

        $this->login('marketplace');
        $this->get('/index.php')->assertStatus(403);
    }
}
