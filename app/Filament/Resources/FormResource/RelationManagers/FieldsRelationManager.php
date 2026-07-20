<?php

namespace App\Filament\Resources\FormResource\RelationManagers;

use App\Models\FormField;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;

class FieldsRelationManager extends RelationManager
{
    protected static string $relationship = 'fields';

    protected static ?string $title = 'Form Fields';

    protected static ?string $recordTitleAttribute = 'label';

    public function form(Form $form): Form
    {
        // Check if the parent form has a custom rating scale
        $hasCustomScale = !empty($this->ownerRecord?->rating_scale);

        return $form
            ->schema([
                Forms\Components\Section::make('Field Configuration')
                    ->schema([
                        Forms\Components\TextInput::make('label')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn($state, $set) => $set('name', \Illuminate\Support\Str::snake($state))),

                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Internal field name (auto-generated from label)'),

                        // When custom scale is enabled, lock type to likert_scale
                        // section_header is excluded — sections are managed via the Form Builder
                        Forms\Components\Select::make('type')
                            ->options(
                                $hasCustomScale
                                ? ['likert_scale' => 'Likert Scale (Rating)']
                                : collect(FormField::TYPES)->except('section_header')->toArray()
                            )
                            ->required()
                            ->live()
                            ->default($hasCustomScale ? 'likert_scale' : null)
                            ->disabled($hasCustomScale)
                            ->dehydrated(true)
                            ->afterStateUpdated(fn($set) => $set('options', null))
                            ->helperText($hasCustomScale
                                ? 'Type is locked to Likert Scale because this form uses a custom rating scale.'
                                : 'Select the input type for this field'),

                        Forms\Components\Toggle::make('is_required')
                            ->label('Required')
                            ->default(false),

                        Forms\Components\TextInput::make('placeholder')
                            ->maxLength(255)
                            ->visible(fn($get) => in_array($get('type'), ['text', 'textarea', 'email', 'tel', 'url', 'number'])),

                        Forms\Components\Textarea::make('help_text')
                            ->label('Help Text')
                            ->rows(2)
                            ->helperText('Additional instructions for the user'),
                    ])->columns(2),

                // Options section - hidden when custom scale is active (likert uses form-level scale)
                Forms\Components\Section::make('Options')
                    ->schema([
                        Forms\Components\Repeater::make('options')
                            ->schema([
                                Forms\Components\TextInput::make('value')
                                    ->required()
                                    ->label('Value'),
                                Forms\Components\TextInput::make('label')
                                    ->required()
                                    ->label('Display Label'),
                            ])
                            ->columns(2)
                            ->addActionLabel('Add Option')
                            ->reorderable()
                            ->collapsible()
                            ->defaultItems(2),
                    ])
                    ->visible(fn($get) => !$hasCustomScale && in_array($get('type'), FormField::TYPES_WITH_OPTIONS))
                    ->collapsible(),

                Forms\Components\Hidden::make('order')
                    ->default(fn() => $this->ownerRecord->fields()->max('order') + 1),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('order')
            ->defaultSort('order')
            ->columns([
                Tables\Columns\TextColumn::make('order')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('section')
                    ->label('Section')
                    ->badge()
                    ->color('primary')
                    ->searchable(),

                Tables\Columns\TextColumn::make('label')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Field Name')
                    ->fontFamily(\Filament\Support\Enums\FontFamily::Mono)
                    ->color('gray'),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn(string $state): string => FormField::TYPES[$state] ?? $state),

                Tables\Columns\IconColumn::make('is_required')
                    ->boolean()
                    ->label('Required'),

                Tables\Columns\TextColumn::make('options')
                    ->label('Options')
                    ->formatStateUsing(fn($state) => is_array($state) ? count($state) . ' options' : '-')
                    ->color('gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(collect(FormField::TYPES)->except('section_header')->toArray()),
            ], layout: FiltersLayout::AboveContent)
            ->modifyQueryUsing(fn($query) => $query->where('type', '!=', 'section_header'))
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Add Field'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
