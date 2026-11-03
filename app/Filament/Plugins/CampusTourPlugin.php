<?php

namespace App\Filament\Plugins;

use Filament\Panel;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use JibayMcs\FilamentTour\FilamentTourPlugin;

class CampusTourPlugin extends FilamentTourPlugin
{
    public function boot(Panel $panel): void
    {
        // Keep the plugin's PHP tour definitions, using one placement-aware client.
        foreach ([...FilamentAsset::getScripts(['jibaymcs/filament-tour']), ...FilamentAsset::getStyles(['jibaymcs/filament-tour'])] as $asset) {
            $asset->loadedOnRequest();
        }
        if (collect(FilamentAsset::getScripts(['app']))->contains(fn ($asset) => $asset->getId() === 'campus-guided-tours')) {
            return;
        }
        FilamentAsset::register([
            Js::make('campus-guided-tours', asset('js/app/campus-guided-tours.js').'?v='.filemtime(public_path('js/app/campus-guided-tours.js'))),
            Css::make('campus-guided-tours', asset('js/app/campus-guided-tours.css').'?v='.filemtime(public_path('js/app/campus-guided-tours.css'))),
        ]);
    }

    public function register(Panel $panel): void
    {
        $panel->renderHook('panels::head.start', fn () => view('filament.partials.guided-tour-assets'));
        $panel->renderHook('panels::body.start', fn () => view('filament.partials.guided-tour-runtime'));
    }
}
