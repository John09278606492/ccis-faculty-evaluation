<?php

namespace App\Providers;

use App\Models\SchoolYear;
use Filament\Support\Facades\FilamentView;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use Kenepa\Banner\Facades\BannerManager;
use Kenepa\Banner\ValueObjects\BannerData;

class SchoolYearBannerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Register a hook that runs after Filament serves a page
        // to check if the current campus has an active school year
        $this->app->booted(function () {
            FilamentView::registerRenderHook(
                'panels::body.start',
                function () {
                    $this->syncSchoolYearBanner();

                    return '';
                }
            );
        });
    }

    /**
     * Check the current tenant's school year status and create/remove the banner accordingly.
     */
    protected function syncSchoolYearBanner(): void
    {
        try {
            $tenant = filament()->getTenant();

            if (! $tenant) {
                return;
            }

            $campusId = $tenant->id;

            // This ran the school-year query and re-wrote the banner cache entry
            // on every single page load for every user. School year status barely
            // ever changes mid-session, so it's throttled to once a minute per campus.
            if (! Cache::add("school_year_banner_throttle_{$campusId}", true, 60)) {
                return;
            }

            $bannerId = 'school_year_warning_campus_'.$campusId;
            $hasActive = SchoolYear::hasActiveForCampus($campusId);

            if (! $hasActive) {
                // Create the warning banner using BannerData::fromArray
                $bannerData = BannerData::fromArray([
                    'id' => $bannerId,
                    'name' => 'No Active School Year',
                    'content' => '⚠️ There is no active School Year for this campus. An administrator needs to create or activate a School Year with valid dates to proceed with evaluations.',
                    'is_active' => true,
                    'active_since' => now()->toDateString(),
                    'icon' => 'heroicon-m-exclamation-triangle',
                    'background_type' => 'gradient',
                    'start_color' => '#DC2626',
                    'end_color' => '#B91C1C',
                    'start_time' => null,
                    'end_time' => null,
                    'can_be_closed_by_user' => true,
                    'text_color' => '#FFFFFF',
                    'icon_color' => '#FEF2F2',
                    'render_location' => 'panels::body.start',
                    'scope' => [],
                ]);

                // Try to update existing, or store new
                try {
                    BannerManager::update($bannerData);
                } catch (\Exception $e) {
                    try {
                        BannerManager::store($bannerData);
                    } catch (\Exception $e) {
                        // Banner might already exist, ignore
                    }
                }
            } else {
                // Remove the warning banner if it exists
                try {
                    BannerManager::delete($bannerId);
                } catch (\Exception $e) {
                    // Banner doesn't exist, ignore
                }
            }
        } catch (\Exception $e) {
            // Silently fail - don't break the app if banner logic fails
        }
    }
}
