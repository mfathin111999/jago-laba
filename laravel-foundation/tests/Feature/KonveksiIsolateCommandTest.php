<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** The copy itself needs MySQL (CREATE USER/GRANT) and is verified on MariaDB; these cover the guards. */
final class KonveksiIsolateCommandTest extends TestCase
{
    public function test_it_refuses_to_run_without_the_explicit_prepare_flag(): void
    {
        config(['jago.konveksi_database_config_path' => sys_get_temp_dir().'/none-'.bin2hex(random_bytes(4)).'.php']);

        $this->assertSame(2, Artisan::call('konveksi:isolate'));
        $this->assertStringContainsString('--prepare', Artisan::output());
    }

    public function test_it_changes_nothing_when_konveksi_is_already_isolated(): void
    {
        $file = sys_get_temp_dir().'/iso-'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($file, '<?php return [];');
        config(['jago.konveksi_database_config_path' => $file]);

        $this->assertSame(0, Artisan::call('konveksi:isolate', ['--prepare' => true]));
        $this->assertStringContainsString('sudah memakai database terpisah', Artisan::output());
        File::delete($file);
    }

    public function test_it_rejects_a_target_that_is_not_a_plain_database_name(): void
    {
        config(['jago.konveksi_database_config_path' => sys_get_temp_dir().'/none-'.bin2hex(random_bytes(4)).'.php']);

        $this->assertSame(1, Artisan::call('konveksi:isolate', ['--prepare' => true, '--target' => 'x`; DROP DATABASE y']));
        $this->assertStringContainsString('tidak valid', Artisan::output());
    }
}
