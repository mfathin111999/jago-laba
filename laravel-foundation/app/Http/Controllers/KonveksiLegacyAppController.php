<?php

namespace App\Http\Controllers;

use App\Auth\LegacyUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * Serves the Konveksi single-page application (the legacy Vue 3 client) behind Laravel's session, company and
 * workspace checks. It assembles the same view files, in the same order, as the legacy index.php; every data call
 * the page makes goes to the Laravel RPC gate at /api/rpc.php with the Laravel CSRF token.
 */
final class KonveksiLegacyAppController
{
    /** Same order as KonveksiModule::viewNames() in the legacy module; a test keeps the two in step. */
    public const VIEWS = [
        '01_Dashboard', '03_Master_Produk', '04_Master_Bahan', '05_HPP_Produksi',
        '06_Master_Stok', '07_Pembelian_Bahan', '08_Pembelian_Vendor', '09_Distribusi',
        '11_SPK_Potong', '12_SPK_Jahit', '13_Penjualan_Produksi', '14_Pegawai',
        '16_Supplier', '17_Keuangan', '18_Rekening', '19_Kasbon', '20_Piutang',
        '21_Hutang_Usaha', '22_Hutang_Konveksi', '23_Setting_Profil', '24_Setting_Pengguna',
        '25_Setting_Hak_Akses', '26_Setting_Backup',
    ];

    public function __invoke(Request $request): Response
    {
        $user = $request->user('web');
        $directory = (string) config('jago.konveksi.views_path');

        $html = $this->view($directory, '00_Base');
        foreach (self::VIEWS as $name) {
            $html .= $this->view($directory, $name);
        }
        $html .= "\n                </div>\n            </main>\n".$this->view($directory, '27_UI_Components');
        $html .= "\n        </template>\n    </div>\n";
        $html .= '<script>window.JAGO_LABA_LOCAL=true;window.JAGO_LABA_LOCAL_VERSION="LARAVEL-HYBRID";window.JL_SESSION='.json_encode([
            'authenticated' => true,
            'workspace' => 'konveksi',
            'csrf' => $request->session()->token(),
            'name' => $user instanceof LegacyUser ? $user->getDisplayName() : '',
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';'
            .'window.jlConfirmWorkspaceSwitch=function(e){if(window.jlUnsavedForm&&!confirm("Ada formulir yang belum disimpan. Ganti ruang kerja?")){e.preventDefault();return false;}return true};'
            .'window.jlUnsavedForm=false;document.addEventListener("input",function(e){if(e.target.closest("form"))window.jlUnsavedForm=true});'
            .'document.addEventListener("submit",function(){window.jlUnsavedForm=false});</script>';
        $html .= '<script src="assets/konveksi/js/jl-api.js?v='.rawurlencode((string) config('jago.konveksi.client_version')).'"></script>';
        $html .= $this->view($directory, '28_Core');

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store, private']);
    }

    private function view(string $directory, string $name): string
    {
        $file = $directory.'/'.$name.'.html';
        $contents = is_file($file) ? file_get_contents($file) : false;
        if ($contents === false) {
            throw new RuntimeException('View Konveksi tidak ditemukan: '.$name);
        }

        return $contents;
    }
}
