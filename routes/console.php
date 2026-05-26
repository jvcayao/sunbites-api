<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Prune activity log entries older than the configured retention period (default: 365 days)
Schedule::command('activitylog:clean')->daily();

// Prune failed queue jobs older than 7 days
Schedule::command('queue:prune-failed --hours=168')->weekly();
