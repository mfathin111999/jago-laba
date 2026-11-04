<?php

/**
 * Prints the assembled Konveksi page exactly as /konveksi would serve it, without a database.
 * Used by konveksi-smoke.mjs.   php tests/visual/render-konveksi.php > page.html
 */

use App\Auth\LegacyUser;
use App\Http\Controllers\KonveksiLegacyAppController;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$session = new Store('smoke', new ArraySessionHandler(120));
$session->put('_token', 'smoke-csrf-token');
$request = Request::create('/konveksi');
$request->setLaravelSession($session);
$request->setUserResolver(static fn () => new LegacyUser(['id' => 'u1', 'username' => 'smoke', 'name' => 'Smoke Test', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN', 'company_key' => 'A']));

echo (new KonveksiLegacyAppController)($request)->getContent();
