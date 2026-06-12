<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

// denný Clarity snapshot (vyžaduje bežiaci `php artisan schedule:work` alebo cron)
Schedule::command('analytics:clarity-snapshot')->dailyAt('20:00');
