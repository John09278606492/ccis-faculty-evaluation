<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Login;
use App\Models\Campus;
use App\Models\User;
use App\Settings\KaidoSetting;
use Filament\Http\Middleware\Authenticate;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use DutchCodingCompany\FilamentSocialite\FilamentSocialitePlugin;
use DutchCodingCompany\FilamentSocialite\Provider;
use Filament\Forms\Components\FileUpload;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Hasnayeen\Themes\Http\Middleware\SetTheme;
use Hasnayeen\Themes\ThemesPlugin;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Jeffgreco13\FilamentBreezy\BreezyCore;
use Rupadana\ApiService\ApiServicePlugin;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Schema;

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
            ->when($this->settings?->login_enabled ?? true, fn($panel) => $panel->login(Login::class))
            ->when($this->settings?->registration_enabled ?? true, fn($panel) => $panel->registration())
            ->when($this->settings?->password_reset_enabled ?? true, fn($panel) => $panel->passwordReset())
            ->emailVerification()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
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
            // ->middleware([
            //     SetTheme::class
            // ])
            ->plugins(
                $this->getPlugins()
            )
            ->databaseNotifications()
            // Multi-tenancy with Campus
            ->tenant(Campus::class)
            ->tenantRegistration(null)
            ->tenantProfile(null);
    }

    private function getPlugins(): array
    {
        $plugins = [
            // ThemesPlugin::make(), // Disabled - causes tenant error before tenant is selected
            FilamentShieldPlugin::make(),
            ApiServicePlugin::make(),
            BreezyCore::make()
                ->myProfile(
                    shouldRegisterUserMenu: true,
                    shouldRegisterNavigation: true,
                    navigationGroup: 'Settings',
                    hasAvatars: true,
                    slug: 'my-profile'
                )
                ->avatarUploadComponent(fn($fileUpload) => $fileUpload->disableLabel())
                ->avatarUploadComponent(
                    fn() => FileUpload::make('avatar_url')
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
                            ->stateless(false)
                    ])->registration(true)
                    ->createUserUsing(function (string $provider, SocialiteUserContract $oauthUser, FilamentSocialitePlugin $plugin) {
                        $user = User::firstOrNew([
                            'email' => $oauthUser->getEmail(),
                        ]);
                        $user->name = $oauthUser->getName();
                        $user->email = $oauthUser->getEmail();
                        $user->email_verified_at = now();
                        $user->save();

                        return $user;
                    });
        }
        return $plugins;
    }
}
