<?php

/**
 * Runs one MANUAL Konveksi backup through the application service and prints the result as JSON.
 * Used by staging-ops-e2e.mjs to prove the mysqldump branch (set JL_MYSQLDUMP in the environment).
 */

use App\Auth\LegacyUser;
use App\Pilots\Konveksi\KonveksiBackupService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$user = new LegacyUser(['id' => 'e2e', 'username' => 'e2e', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN', 'company_key' => getenv('JL_COMPANY_KEY') ?: 'LOCAL-COMPANY']);
echo json_encode($app->make(KonveksiBackupService::class)->create($user, 'MANUAL'), JSON_UNESCAPED_SLASHES);
