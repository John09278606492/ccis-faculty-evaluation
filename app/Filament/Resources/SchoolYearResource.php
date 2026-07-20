<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SchoolYearResource\Pages;
use App\Filament\Resources\SchoolYearResource\RelationManagers;
use App\Models\SchoolYear;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;

class SchoolYearResource extends Resource
{
    protected static ?string $model = SchoolYear::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    protected static ?string $navigationGroup = 'Academic Management';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('School Year Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(50)
                            ->placeholder('e.g., 2025-2026')
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('campus_id', filament()->getTenant()?->id)),

                        Forms\Components\DatePicker::make('start_date')
                            ->required()
                            ->native(false),

                        Forms\Components\DatePicker::make('end_date')
                            ->required()
                            ->native(false)
                            ->after('start_date'),

                        Forms\Components\Toggle::make('is_current')
                            ->label('Current School Year')
                            ->helperText('Only one School Year can be active at a time. Enabling this will deactivate any other current School Year for this campus.'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('start_date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('end_date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('semesters_count')
                    ->counts('semesters')
                    ->label('Semesters')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_current')
                    ->boolean()
                    ->label('Current')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('start_date', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_current')
                    ->label('Current Year'),
            ], layout: FiltersLayout::AboveContent)
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\SemestersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSchoolYears::route('/'),
            'create' => Pages\CreateSchoolYear::route('/create'),
            'edit' => Pages\EditSchoolYear::route('/{record}/edit'),
        ];
    }
}
