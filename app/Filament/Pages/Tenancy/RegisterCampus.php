<?php

namespace App\Filament\Pages\Tenancy;

use App\Models\Campus;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Pages\Tenancy\RegisterTenant;
use Illuminate\Support\Str;

class RegisterCampus extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Register Campus';
    }

    /**
     * Only super_admin can register new campuses/tenants.
     */
    public static function canView(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Campus Information')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state))),

                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique('campuses', 'slug'),

                        Textarea::make('description')
                            ->maxLength(1000),
                    ])->columns(2),

                Section::make('Contact Information')
                    ->schema([
                        TextInput::make('email')
                            ->email()
                            ->maxLength(255),

                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(20),

                        TextInput::make('website')
                            ->url()
                            ->maxLength(255),
                    ])->columns(2),

                Section::make('Address')
                    ->schema([
                        TextInput::make('address')
                            ->maxLength(255)
                            ->columnSpanFull(),

                        TextInput::make('city')
                            ->maxLength(100),

                        TextInput::make('state')
                            ->maxLength(100),

                        TextInput::make('postal_code')
                            ->maxLength(20),

                        TextInput::make('country')
                            ->maxLength(100),
                    ])->columns(2),

                Section::make('Branding')
                    ->schema([
                        FileUpload::make('logo_url')
                            ->image()
                            ->maxSize(5 * 1024)
                            ->directory('campuses'),
                    ]),
            ]);
    }

    protected function handleRegistration(array $data): Campus
    {
        $campus = Campus::create($data);

        // Add the current user as a member of this campus
        $campus->users()->attach(auth()->user());

        return $campus;
    }
}
