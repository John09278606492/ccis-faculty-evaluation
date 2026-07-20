<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\QuizNotificationServiceProvider;
use App\Providers\SchoolYearBannerServiceProvider;
use SocialiteProviders\Manager\ServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    SchoolYearBannerServiceProvider::class,
    QuizNotificationServiceProvider::class,
    ServiceProvider::class, // add
];
