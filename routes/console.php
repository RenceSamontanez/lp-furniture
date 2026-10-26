<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily housekeeping at 03:00 Manila time: orphan uploads, expired temp files, expired sessions, failed jobs older than 14 days.
// Read notifications are not part of the scheduled run (run `storage:cleanup --notifications --force` by hand if wanted).
// Needs the usual `* * * * * php artisan schedule:run` cron / Task Scheduler entry.
Schedule::command('storage:cleanup --force --sessions --failed-jobs')
    ->dailyAt('03:00')
    ->timezone('Asia/Manila')
    ->withoutOverlapping();
