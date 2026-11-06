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

        $this->createWorkspaceAccessTable();
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

        $response = $this->get('/konveksi')->assertOk();
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

        $html = $this->get('/konveksi')->getContent();

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

    public function test_every_view_the_client_is_built_from_ships_with_laravel(): void
    {
        $this->assertSame(resource_path('konveksi/views'), config('jago.konveksi.views_path'));
        foreach (['00_Base', '27_UI_Components', '28_Core', ...KonveksiLegacyAppController::VIEWS] as $view) {
            $this->assertFileExists(config('jago.konveksi.views_path').'/'.$view.'.html');
        }
        $this->assertSame(array_values(array_unique(KonveksiLegacyAppController::VIEWS)), KonveksiLegacyAppController::VIEWS, 'no view is listed twice');
    }

    public function test_other_workspaces_and_guests_do_not_get_the_client(): void
    {
        $this->get('/konveksi')->assertRedirect();

        $this->login('marketplace');
        $this->get('/konveksi')->assertStatus(403);
    }
}
