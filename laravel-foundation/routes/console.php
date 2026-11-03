<?php

use App\Pilots\Konveksi\KonveksiBackupService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('marketplace:sync --mode=cron')
    ->everyFiveMinutes()
    ->withoutOverlapping(1);

Schedule::command('marketplace:auto-sync')
    ->hourly()
    ->withoutOverlapping(1);

Schedule::command('marketplace:store-deletion --pending')
    ->everyMinute()
    ->withoutOverlapping(10);

Schedule::call(fn () => app(KonveksiBackupService::class)->processPending())
    ->name('konveksi:pending-snapshot')
    ->everyMinute()
    ->withoutOverlapping(10);
