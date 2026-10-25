<?php

use App\Services\DatabaseNotificationActions;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// One backup a day. Checked hourly so a computer that is off at night still
// gets that day's backup the next time the classroom server is open.
Schedule::command('classroom:backup --if-due')->hourly()->withoutOverlapping()->runInBackground();

Schedule::command('classroom:assessments-maintain')->everyMinute()->withoutOverlapping()->runInBackground();
Schedule::command('background:maintain')->everyTenMinutes()->withoutOverlapping()->runInBackground();
Schedule::call(fn () => app(DatabaseNotificationActions::class)->flushAll())
    ->name('sync-notification-actions')->everyMinute()->withoutOverlapping();

Artisan::command('workspace-drafts:prune', function () {
    $count = \App\Models\WorkspaceDraft::where('expires_at', '<=', now())->delete();
    $this->info("Removed {$count} expired working drafts.");
})->purpose('Remove expired private working drafts');
Schedule::command('workspace-drafts:prune')->daily()->withoutOverlapping();
