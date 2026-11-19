<?php

namespace App\Filament\Pages;

use App\Services\OnboardingGuide;
use Filament\Pages\Page;

class GettingStarted extends Page
{
    protected static string $view = 'filament.pages.getting-started';

    protected static ?string $title = 'Start here';

    protected static ?string $navigationLabel = 'Start here';

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?int $navigationSort = -10;

    public static function canAccess(): bool
    {
        return auth()->user() && filament()->getTenant()
            && auth()->user()->canAccessTenant(filament()->getTenant());
    }

    public function guide(): array
    {
        abort_unless(static::canAccess(), 403);

        return app(OnboardingGuide::class)->forCurrentUser();
    }
}
