<?php

namespace App\Filament\Pages\Tenancy;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Pages\Tenancy\EditTenantProfile;

class EditCampusProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Campus Profile';
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Basic Information')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique('campuses', 'slug', ignoreRecord: true),

                        Textarea::make('description')
                            ->maxLength(1000)
                            ->columnSpanFull(),
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

                Section::make('Branding & Status')
                    ->schema([
                        FileUpload::make('logo_url')
                            ->image()
                            ->maxSize(5 * 1024)
                            ->directory('campuses'),

                        Toggle::make('is_active')
                            ->default(true),
                    ])->columns(2),
            ]);
    }
}
