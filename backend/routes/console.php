<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// PRODUCTION: nothing runs `schedule:run`. A Railway cron service named `scheduler` runs `php artisan scheduler:run-hourly` once an hour (UTC), which runs
// the three commands below in the hours given here (00, 02 and 08-20 UTC) and drains the notification queues (see RunHourlyScheduler). These definitions stay
// as the single description of the tasks (and for `php artisan schedule:work` while developing); a test keeps the two lists the same.

// Unified File Upload Service: staged uploads expire after 7 days (API §14.8).
Schedule::command('uploads:purge-expired')->daily()->withoutOverlapping();

// HR imports: delete personal data from import staging 30 days after upload (UTC). Also runs opportunistically
// on every import upload, so retention does not depend on the scheduler alone.
Schedule::command('hr:purge-import-staging')->dailyAt('02:30')->timezone('UTC')->withoutOverlapping();

// Marketing: fires a reminder for every follow-up due today (HRM_MARKETING_SRS.md §14-15).
Schedule::command('follow-ups:notify-due')->dailyAt('08:00')->withoutOverlapping();
