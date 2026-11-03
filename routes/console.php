<?php

use App\Models\WorkspaceDraft;
use App\Services\DatabaseNotificationActions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// One backup a day. Checked hourly so a computer that is off at night still
// gets that day's backup the next time the classroom server is open.
Schedule::command('classroom:backup --if-due')->hourly()->withoutOverlapping()->runInBackground();

Schedule::command('classroom:assessments-maintain')->everyMinute()->withoutOverlapping()->runInBackground();
Schedule::command('classroom:evaluations-maintain')->everyMinute()->withoutOverlapping()->runInBackground();
Schedule::command('background:maintain')->everyTenMinutes()->withoutOverlapping()->runInBackground();
Schedule::call(fn () => app(DatabaseNotificationActions::class)->flushAll())
    ->name('sync-notification-actions')->everyMinute()->withoutOverlapping();

Artisan::command('workspace-drafts:prune', function () {
    $count = WorkspaceDraft::where('expires_at', '<=', now())->delete();
    $this->info("Removed {$count} expired working drafts.");
})->purpose('Remove expired private working drafts');
Schedule::command('workspace-drafts:prune')->daily()->withoutOverlapping();
