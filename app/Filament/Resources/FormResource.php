<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FormResource\Pages;
use App\Filament\Resources\FormResource\RelationManagers;
use App\Models\Form;
use App\Models\Department;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use Filament\Forms;
use Filament\Forms\Form as FilamentForm;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class FormResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = Form::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Forms';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Form Builder';

    // Disable built-in tenant scoping — we use custom scoping via pivot table
    protected static bool $isScopedToTenant = false;

    /**
     * Define permission prefixes for Shield.
     */
    public static function getPermissionPrefixes(): array
    {
        return [
            'view',
            'view_any',
            'create',
            'update',
            'delete',
            'delete_any',
        ];
    }

    /**
     * Only show navigation if user has view_any permission.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->can('view_any_form') ?? false;
    }

    /**
     * Scope forms to the current tenant campus (via pivot table).
     */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery();

        // Super admin sees all forms
        if (auth()->user()?->hasRole('super_admin')) {
            return $query;
        }

        // Other users only see forms assigned to their current campus
        $campusId = filament()->getTenant()?->id;
        if ($campusId) {
            $query->whereHas('campuses', function ($q) use ($campusId) {
                $q->where('campuses.id', $campusId);
            });
        }

        return $query;
    }

    /**
     * Non-super_admin cannot create new forms.
     */
    public static function canCreate(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function form(FilamentForm $form): FilamentForm
    {
        $isSuperAdmin = auth()->user()?->hasRole('super_admin');

        return $form
            ->schema([
                // Campus selector - multi-select, only visible to super_admin
                Forms\Components\Section::make('Campus Assignment')
                    ->schema([
                        Forms\Components\Select::make('campuses')
                            ->label('Campuses')
                            ->relationship('campuses', 'name')
                            ->multiple()
                            ->required()
                            ->searchable()
                            ->preload()
                            ->default(fn() => filament()->getTenant() ? [filament()->getTenant()->id] : [])
                            ->helperText('Select which campuses can use this form'),
                    ])
                    ->visible($isSuperAdmin)
                    ->collapsible(),

                Forms\Components\Section::make('Form Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn($state, $set) => $set('slug', \Illuminate\Support\Str::slug($state) . '-' . \Illuminate\Support\Str::random(6)))
                            ->disabled(!$isSuperAdmin),

                        Forms\Components\TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Used in the form URL')
                            ->disabled(!$isSuperAdmin),

                        Forms\Components\Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull()
                            ->disabled(!$isSuperAdmin),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Only active forms can receive submissions')
                            ->disabled(!$isSuperAdmin),

                        Forms\Components\Toggle::make('requires_login')
                            ->label('Require Login')
                            ->default(false)
                            ->helperText('Users must be logged in to submit')
                            ->disabled(!$isSuperAdmin),
                    ])->columns(2)
                    ->description(!$isSuperAdmin ? 'Only super admins can edit form details.' : null),

                // Department Assignment - EDITABLE for all users with permission
                Forms\Components\Section::make('Department Assignment')
                    ->schema([
                        Forms\Components\Select::make('departments')
                            ->label('Assign to Departments')
                            ->relationship('departments', 'name', function ($query) {
                                $campusId = filament()->getTenant()?->id;
                                return $query->where('campus_id', $campusId)->where('is_active', true);
                            })
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->helperText('Select departments that can use this form. Leave empty for campus-wide.'),
                    ]),

                Forms\Components\Section::make('Evaluator Access')
                    ->schema([
                        Forms\Components\CheckboxList::make('evaluator_roles')
                            ->label('Who can access this form?')
                            ->options(function () {
                                return \Spatie\Permission\Models\Role::query()
                                    ->where('name', '!=', 'super_admin')
                                    ->pluck('name', 'name')
                                    ->mapWithKeys(fn($name, $key) => [$key => ucwords(str_replace('_', ' ', $key))])
                                    ->toArray();
                            })
                            ->columns(3)
                            ->helperText('Select which roles can evaluate using this form. Leave empty to allow all roles.')
                            ->disabled(!$isSuperAdmin),
                    ])
                    ->visible($isSuperAdmin),

                Forms\Components\Section::make('Rating Scale Configuration')
                    ->description('Define the rating scale for Likert Scale fields. If left empty, uses default 5-point scale.')
                    ->schema([
                        Forms\Components\Toggle::make('use_custom_scale')
                            ->label('Use Custom Rating Scale')
                            ->live()
                            ->dehydrated(false)
                            ->default(fn($record) => !empty($record?->rating_scale)),

                        Forms\Components\Repeater::make('rating_scale')
                            ->label('Rating Scale Options')
                            ->schema([
                                Forms\Components\TextInput::make('value')
                                    ->label('Value')
                                    ->required()
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(10),
                                Forms\Components\TextInput::make('label')
                                    ->label('Label')
                                    ->required()
                                    ->placeholder('e.g., Outstanding'),
                                Forms\Components\TextInput::make('description')
                                    ->label('Description')
                                    ->placeholder('e.g., Exceeds expectations'),
                                Forms\Components\ColorPicker::make('color')
                                    ->label('Color'),
                            ])
                            ->columns(4)
                            ->addActionLabel('Add Rating')
                            ->reorderable()
                            ->defaultItems(0)
                            ->visible(fn($get) => $get('use_custom_scale'))
                            ->helperText('Define each rating from highest to lowest'),
                    ])
                    ->collapsible()
                    ->collapsed()
                    ->visible($isSuperAdmin),

                Forms\Components\Section::make('Scoring & Calculation')
                    ->description('Configure how section and overall scores are calculated.')
                    ->schema([
                        Forms\Components\Select::make('scoring_config.section_method')
                            ->label('Section Score Calculation')
                            ->options([
                                'average' => 'Average — Mean of all ratings in the section',
                                'sum' => 'Sum — Total of all ratings in the section',
                                'percentage' => 'Percentage — Score as % of maximum possible',
                            ])
                            ->default('average')
                            ->helperText('How to compute each section\'s score from individual ratings'),

                        Forms\Components\Select::make('scoring_config.overall_method')
                            ->label('Overall Score Calculation')
                            ->options([
                                'average' => 'Average — Mean of all section scores',
                                'sum' => 'Sum — Total of all section scores',
                                'weighted_average' => 'Weighted Average — Sections have different weights',
                                'grand_average' => 'Grand Average — Average of ALL individual ratings (ignoring sections)',
                            ])
                            ->default('average')
                            ->live()
                            ->helperText('How to compute the overall score from section scores'),

                        Forms\Components\Repeater::make('scoring_config.section_weights')
                            ->label('Section Weights')
                            ->schema([
                                Forms\Components\TextInput::make('section_name')
                                    ->label('Section Name')
                                    ->required()
                                    ->placeholder('e.g., A. Commitment'),
                                Forms\Components\TextInput::make('weight')
                                    ->label('Weight (%)')
                                    ->required()
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->suffix('%')
                                    ->placeholder('e.g., 25'),
                            ])
                            ->columns(2)
                            ->addActionLabel('Add Section Weight')
                            ->visible(fn($get) => $get('scoring_config.overall_method') === 'weighted_average')
                            ->helperText('Define weights for each section. Total should equal 100%.'),
                    ])
                    ->collapsible()
                    ->collapsed()
                    ->visible($isSuperAdmin),

                Forms\Components\Section::make('Comments Configuration')
                    ->description('Configure whether evaluators can or must leave comments.')
                    ->schema([
                        Forms\Components\Select::make('scoring_config.field_comments')
                            ->label('Field Comments')
                            ->options([
                                'hidden' => 'Hidden — No comment fields per question',
                                'optional' => 'Optional — Evaluators may leave comments per question',
                                'required' => 'Required — Evaluators must comment on each question',
                            ])
                            ->default('optional')
                            ->helperText('Controls the comment field shown below each individual question/criteria'),

                        Forms\Components\Select::make('scoring_config.overall_comment')
                            ->label('Overall Comment')
                            ->options([
                                'hidden' => 'Hidden — No overall comment field',
                                'optional' => 'Optional — Evaluators may leave an overall comment',
                                'required' => 'Required — Evaluators must leave an overall comment',
                            ])
                            ->default('optional')
                            ->helperText('Controls the overall comment field shown before the submit button'),
                    ])
                    ->collapsible()
                    ->collapsed()
                    ->visible($isSuperAdmin),

                Forms\Components\Section::make('Schedule & Limits')
                    ->schema([
                        Forms\Components\DateTimePicker::make('starts_at')
                            ->label('Start Date')
                            ->helperText('Form will be available from this date')
                            ->disabled(!$isSuperAdmin),

                        Forms\Components\DateTimePicker::make('ends_at')
                            ->label('End Date')
                            ->helperText('Form will close after this date')
                            ->disabled(!$isSuperAdmin),

                        Forms\Components\TextInput::make('max_submissions')
                            ->label('Max Submissions')
                            ->numeric()
                            ->minValue(1)
                            ->helperText('Maximum number of submissions allowed (leave empty for unlimited)')
                            ->disabled(!$isSuperAdmin),
                    ])->columns(3)
                    ->collapsible()
                    ->visible($isSuperAdmin),
            ]);
    }

    public static function table(Table $table): Table
    {
        $isSuperAdmin = auth()->user()?->hasRole('super_admin');

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('campuses.name')
                    ->label('Campuses')
                    ->badge()
                    ->color('success')
                    ->separator(', ')
                    ->searchable(),

                Tables\Columns\TextColumn::make('departments.name')
                    ->label('Departments')
                    ->badge()
                    ->separator(', ')
                    ->default('All Departments'),

                Tables\Columns\TextColumn::make('fields_count')
                    ->counts('fields')
                    ->label('Fields')
                    ->sortable(),

                Tables\Columns\TextColumn::make('submissions_count')
                    ->counts('submissions')
                    ->label('Submissions')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),

                Tables\Columns\IconColumn::make('requires_login')
                    ->boolean()
                    ->label('Login Required'),

                Tables\Columns\TextColumn::make('starts_at')
                    ->dateTime('M j, Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('ends_at')
                    ->dateTime('M j, Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
                Tables\Filters\TernaryFilter::make('requires_login'),
                Tables\Filters\SelectFilter::make('departments')
                    ->relationship('departments', 'name'),
            ])
            ->actions([
                Tables\Actions\Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->color('warning')
                    ->url(fn($record) => route('form.preview', $record))
                    ->openUrlInNewTab(),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible($isSuperAdmin),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible($isSuperAdmin),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        $isSuperAdmin = auth()->user()?->hasRole('super_admin');

        // Non-super_admin cannot edit form fields, only view submissions
        if (!$isSuperAdmin) {
            return [
                RelationManagers\SubmissionsRelationManager::class,
            ];
        }

        return [
            RelationManagers\FieldsRelationManager::class,
            RelationManagers\SubmissionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListForms::route('/'),
            'create' => Pages\CreateForm::route('/create'),
            'edit' => Pages\EditForm::route('/{record}/edit'),
        ];
    }
}
