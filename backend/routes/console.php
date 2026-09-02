<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Unified File Upload Service: staged uploads expire after 7 days (API §14.8).
Schedule::command('uploads:purge-expired')->daily();

// Marketing: fires a reminder for every follow-up due today (HRM_MARKETING_SRS.md §14-15).
Schedule::command('follow-ups:notify-due')->dailyAt('08:00');
