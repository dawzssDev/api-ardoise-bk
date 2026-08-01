<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Cola sin supervisor (Hostinger Cloud Startup)
|--------------------------------------------------------------------------
| El cron de hPanel ejecuta `php artisan schedule:run` cada minuto.
| Este comando drena la cola database y termina solo (sin daemon permanente).
*/
Schedule::command('queue:work database --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();
