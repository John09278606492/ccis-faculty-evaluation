<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// One backup a day. Checked hourly so a computer that is off at night still
// gets that day's backup the next time the classroom server is open.
Schedule::command('classroom:backup --if-due')->hourly()->withoutOverlapping()->runInBackground();
