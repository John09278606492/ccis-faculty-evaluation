<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Login;
use App\Models\Campus;
use App\Models\User;
use App\Settings\KaidoSetting;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use DutchCodingCompany\FilamentSocialite\FilamentSocialitePlugin;
use DutchCodingCompany\FilamentSocialite\Provider;
use Filament\Forms\Components\FileUpload;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Hasnayeen\Themes\ThemesPlugin;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Jeffgreco13\FilamentBreezy\BreezyCore;
use Kenepa\Banner\BannerPlugin;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Rupadana\ApiService\ApiServicePlugin;

class AdminPanelProvider extends PanelProvider
{
    private ?KaidoSetting $settings = null;

    public function __construct()
    {
        // Check if settings table exists first
        try {
            if (Schema::hasTable('settings')) {
                $this->settings = app(KaidoSetting::class);
            }
        } catch (\Exception $e) {
            $this->settings = null;
        }
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->when($this->settings?->login_enabled ?? true, fn ($panel) => $panel->login(Login::class))
            ->when($this->settings?->registration_enabled ?? true, fn ($panel) => $panel->registration())
            ->when($this->settings?->password_reset_enabled ?? true, fn ($panel) => $panel->passwordReset())
            ->emailVerification()
            ->maxContentWidth(MaxWidth::Full)
            ->colors([
                'cyan' => Color::Cyan,         // Stays the same for a neutral/calm tone
                'danger' => Color::Red,        // Red is more universally recognized for danger
                'gray' => Color::Slate,         // Zinc is a more modern gray shade
                'info' => Color::Sky,          // Sky Blue is softer and fits information purposes
                'primary' => Color::Indigo,      // Blue is commonly used as a primary action color
                'success' => Color::Green,     // Green is more recognizable for success than Emerald
                'warning' => Color::Amber,     // Amber is a stronger warning color than Orange
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([])
            ->navigationGroups([
                NavigationGroup::make('Evaluations')
                    ->icon('heroicon-o-clipboard-document-list'),
                NavigationGroup::make('Quizzes')
                    ->icon('heroicon-o-pencil-square'),
                NavigationGroup::make('Student')
                    ->icon('heroicon-o-academic-cap'),
                NavigationGroup::make('Academic Management')
                    ->icon('heroicon-o-building-library')
                    ->collapsed(),
                NavigationGroup::make('Forms')
                    ->icon('heroicon-o-document-text')
                    ->collapsed(),
                NavigationGroup::make('User Management')
                    ->icon('heroicon-o-users')
                    ->collapsed(),
                NavigationGroup::make('Settings')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->collapsed(),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->sidebarCollapsibleOnDesktop(true)
            ->authMiddleware([
                Authenticate::class,
            ])
            ->plugins(
                $this->getPlugins()
            )
            ->databaseNotifications()
            // Multi-tenancy with Campus
            ->tenant(Campus::class)
            ->tenantRegistration(null)
            ->tenantProfile(null)
            ->viteTheme('resources/css/filament/admin/theme.css');
    }

    private function getPlugins(): array
    {
        $plugins = [
            // ThemesPlugin::make(), // Disabled - causes tenant error before tenant is selected
            FilamentShieldPlugin::make(),
            ApiServicePlugin::make(),
            BannerPlugin::make()
                ->disableBannerManager(),
            BreezyCore::make()
                ->myProfile(
                    shouldRegisterUserMenu: true,
                    shouldRegisterNavigation: true,
                    navigationGroup: 'Settings',
                    hasAvatars: true,
                    slug: 'my-profile'
                )
                ->avatarUploadComponent(fn ($fileUpload) => $fileUpload->disableLabel())
                ->avatarUploadComponent(
                    fn () => FileUpload::make('avatar_url')
                        ->image()
                        ->disk('public')
                )
                ->enableTwoFactorAuthentication(),
        ];

        if ($this->settings?->sso_enabled ?? true) {
            $plugins[] =
                FilamentSocialitePlugin::make()
                    ->providers([
                        Provider::make('google')
                            ->label('Google')
                            ->icon('fab-google')
                            ->color(Color::hex('#2f2a6b'))
                            ->outlined(true)
                            ->stateless(false),
                    ])->registration(true)
                    ->createUserUsing(function (string $provider, SocialiteUserContract $oauthUser, FilamentSocialitePlugin $plugin) {
                        $user = User::firstOrNew([
                            'email' => $oauthUser->getEmail(),
                        ]);
                        $user->name = $oauthUser->getName();
                        $user->email = $oauthUser->getEmail();
                        $user->email_verified_at = now();
                        $user->save();

                        // Assign default role if user has no roles yet
                        if ($user->roles()->count() === 0) {
                            $user->assignRole('student');
                        }

                        return $user;
                    });
        }

        return $plugins;
    }
}
