<?php

namespace App\Providers;

use App\Models\Quiz;
use Filament\Support\Facades\FilamentView;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

class QuizNotificationServiceProvider extends ServiceProvider
{
    /**
     * There's no queue worker or cron guaranteed to be running on a classroom LAN
     * box, so — same approach as SchoolYearBannerServiceProvider — this checks for
     * due "starting soon" quiz alerts on every authenticated panel page load
     * instead of relying on a scheduler.
     */
    public function boot(): void
    {
        $this->app->booted(function () {
            FilamentView::registerRenderHook(
                'panels::body.start',
                function () {
                    $this->checkDueQuizNotifications();

                    return '';
                }
            );
        });
    }

    protected function checkDueQuizNotifications(): void
    {
        try {
            if (! filament()->getTenant()) {
                return;
            }

            // This query runs on every authenticated page load (there's no cron
            // here), so it's throttled to at most once every 15 seconds
            // system-wide — plenty frequent for a notification window, and it
            // stops every single request from paying for it.
            if (! Cache::add('quiz_notifications_throttle', true, 15)) {
                return;
            }

            Quiz::sendDueStartingSoonNotifications();
        } catch (\Exception $e) {
            // Silently fail - don't break the app if notification logic fails
        }
    }
}
