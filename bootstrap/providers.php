<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    App\Providers\SchoolYearBannerServiceProvider::class,
    App\Providers\QuizNotificationServiceProvider::class,
    \SocialiteProviders\Manager\ServiceProvider::class, // add
];
