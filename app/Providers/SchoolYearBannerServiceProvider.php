<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Kenepa\Banner\Facades\BannerManager;

class SchoolYearBannerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // WorkspaceContext now renders a live, tenant-scoped warning. Retire only
        // this provider's old global banners; preserve administrator announcements.
        $this->app->booted(function () {
            try {
                foreach (BannerManager::getAll() as $banner) {
                    if (str_starts_with($banner->id, 'school_year_warning_campus_')) {
                        BannerManager::delete($banner->id);
                    }
                }
            } catch (\Throwable) {
                // Initial installation may not have a configured banner store yet.
            }
        });
    }
}
