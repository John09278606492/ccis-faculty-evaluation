<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FormResource\Pages;
use App\Filament\Resources\FormResource\RelationManagers;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Form;
use App\Services\EvaluationTemplates;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use Filament\Forms;
use Filament\Forms\Form as FilamentForm;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class FormResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = Form::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Form Templates';

    protected static ?string $modelLabel = 'Evaluation Form';

    protected static ?string $pluralModelLabel = 'Form Templates';

    // Disable built-in tenant scoping â€” we use custom scoping via pivot table
    protected static bool $isScopedToTenant = false;

    /**
     * Define permission prefixes for Shield.
     */
    public static function canDelete(Model $record): bool
    {
        return ! $record->isLocked() && ! CourseOffering::where('form_id', $record->id)->exists() && parent::canDelete($record);
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPermissionPrefixes(): array
    {
        return [
            'view',
            'view_any',
            'create',
            'update',
            'delete',
            'delete_any',
            'view_evaluator_identity',
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
    public static function getEloquentQuery(): Builder
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

    public static function form(FilamentForm $form): FilamentForm
    {
        // Full form configuration (as opposed to just department assignment)
        // requires update_form, so it's toggleable per-role in Roles.
        $isSuperAdmin = auth()->user()?->can('update_form') ?? false;

        return $form
            ->disabled(fn (?Form $record) => $record?->isLocked() ?? false)
            ->schema([
                // Campus selector - multi-select, only visible to super_admin
                Forms\Components\Section::make('Overall comments')
                    ->description(fn (?Form $record) => $record?->isLocked()
                        ? 'Use the Overall comments button above to change the requirement for new submissions.'
                        : 'Choose whether an overall comment is required before submitting this form.')
                    ->schema([
                        Forms\Components\Select::make('scoring_config.overall_comment')
                            ->label('Overall comments')
                            ->options(['required' => 'Required', 'optional' => 'Optional', 'hidden' => 'Hidden'])
                            ->default('optional')
                            ->required()
                            ->in(['required', 'optional', 'hidden']),
                    ])
                    ->visible($isSuperAdmin),

                Forms\Components\Section::make('Campus Assignment')
                    ->schema([
                        Forms\Components\Select::make('campuses')
                            ->label('Campuses')
                            ->relationship('campuses', 'name')
                            ->multiple()
                            ->required()
                            ->searchable()
                            ->preload()
                            ->default(fn () => filament()->getTenant() ? [filament()->getTenant()->id] : [])
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
                            ->afterStateUpdated(fn ($state, $set) => $set('slug', Str::slug($state).'-'.Str::random(6)))
                            ->disabled(! $isSuperAdmin),

                        Forms\Components\TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Used in the form URL')
                            ->disabled(! $isSuperAdmin),

                        Forms\Components\Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull()
                            ->disabled(! $isSuperAdmin),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Only active forms can receive submissions')
                            ->disabled(! $isSuperAdmin),

                        Forms\Components\Toggle::make('requires_login')
                            ->label('Require evaluator identification')
                            ->default(false)
                            ->helperText('Students enter their student ID only. Staff accounts must have an allowed evaluator role.')
                            ->disabled(! $isSuperAdmin),
                    ])->columns(2)
                    ->description(! $isSuperAdmin ? 'Only super admins can edit form details.' : null),

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
                                return Role::query()
                                    ->where('name', '!=', 'super_admin')
                                    ->pluck('name', 'name')
                                    ->mapWithKeys(fn ($name, $key) => [$key => ucwords(str_replace('_', ' ', $key))])
                                    ->toArray();
                            })
                            ->columns(3)
                            ->helperText(fn (?Form $record) => $record?->isSupervisorInstrument()
                                ? 'Annex B ignores roles. Only supervisors explicitly selected for each faculty under Teaching Classes can answer it.'
                                : 'Select which roles can evaluate using this form. Leave empty to allow all roles.')
                            ->disabled(! $isSuperAdmin),
                    ])
                    ->visible($isSuperAdmin),

                Forms\Components\Section::make('Rating Scale Configuration')
                    ->description('Define the rating scale for Likert Scale fields. When enabled, the form builder will only allow Likert Scale fields.')
                    ->schema([
                        Forms\Components\Toggle::make('use_custom_scale')
                            ->label('Use Custom Rating Scale')
                            ->live()
                            ->dehydrated(false)
                            ->default(fn ($record) => ! empty($record?->rating_scale))
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                if (! $state) {
                                    $set('rating_scale', null);
                                }
                            }),

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
                            ->visible(fn ($get) => $get('use_custom_scale'))
                            ->helperText('Define each rating from highest to lowest'),
                    ])
                    ->collapsible()
                    ->collapsed()
                    ->visible($isSuperAdmin),

                Forms\Components\Section::make('Instrument & Report Format')
                    ->description('Choose where this form appears in the Annex D summary. Copies retain this setting; adjust it when creating a different instrument.')
                    ->schema([
                        Forms\Components\Select::make('settings.instrument')->label('Summary report category')
                            ->options(['SET' => 'Student Evaluation of Teachers (SET)', 'SEF' => 'Supervisorâ€™s Evaluation of Faculty (SEF)', 'Other' => 'Other evaluation'])->default('Other'),
                        Forms\Components\TextInput::make('settings.program_level')->label('Program Level / Year')->maxLength(255),
                        Forms\Components\TextInput::make('settings.academic_rank')->label('Academic Rank')->maxLength(255),
                    ])->columns(3)->disabled(! $isSuperAdmin),

                Forms\Components\Section::make('Scoring & Calculation')
                    ->description('Configure how section and overall scores are calculated.')
                    ->schema([
                        Forms\Components\Select::make('scoring_config.section_method')
                            ->label('Section Score Calculation')
                            ->options([
                                'average' => 'Average â€” Mean of all ratings in the section',
                                'sum' => 'Sum â€” Total of all ratings in the section',
                                'percentage' => 'Percentage â€” Score as % of maximum possible',
                            ])
                            ->default('average')
                            ->helperText('How to compute each section\'s score from individual ratings'),

                        Forms\Components\Select::make('scoring_config.overall_method')
                            ->label('Overall Score Calculation')
                            ->options([
                                'average' => 'Average â€” Mean of all section scores',
                                'sum' => 'Sum â€” Total of all section scores',
                                'weighted_average' => 'Weighted Average â€” Sections have different weights',
                                'grand_average' => 'Grand Average â€” Average of ALL individual ratings (ignoring sections)',
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
                            ->visible(fn ($get) => $get('scoring_config.overall_method') === 'weighted_average')
                            ->helperText('Define weights for each section. Total should equal 100%.'),
                    ])
                    ->collapsible()
                    ->collapsed()
                    ->visible(fn (Forms\Get $get): bool => $isSuperAdmin && (bool) $get('use_custom_scale')),

                Forms\Components\Section::make('Schedule & Limits')
                    ->schema([
                        Forms\Components\DateTimePicker::make('starts_at')
                            ->label('Start Date')
                            ->helperText('Form will be available from this date')
                            ->disabled(! $isSuperAdmin),

                        Forms\Components\DateTimePicker::make('ends_at')
                            ->label('End Date')
                            ->helperText('Form will close after this date')
                            ->disabled(! $isSuperAdmin),

                        Forms\Components\TextInput::make('max_submissions')
                            ->label('Max Submissions')
                            ->numeric()
                            ->minValue(1)
                            ->helperText('Maximum number of submissions allowed (leave empty for unlimited)')
                            ->disabled(! $isSuperAdmin),
                    ])->columns(3)
                    ->collapsible()
                    ->visible($isSuperAdmin),
            ]);
    }

    public static function table(Table $table): Table
    {
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
                    ->label('Identification required'),

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
                Tables\Filters\TernaryFilter::make('requires_login')->label('Identification required'),
                Tables\Filters\SelectFilter::make('departments')
                    ->relationship('departments', 'name'),
            ], layout: FiltersLayout::AboveContent)
            ->actions([
                Tables\Actions\Action::make('annexPreview')->label('Print Instrument')->icon('heroicon-o-printer')
                    ->modalHeading('Print Instrument')->modalWidth('7xl')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn ($record) => view('reports.preview-frame', ['url' => route('form.preview', ['form' => $record, 'print' => 1]), 'title' => 'Instrument print preview', 'hint' => 'Review the instrument and use Print / Save PDF inside the preview.'])),
                Tables\Actions\Action::make('duplicate')->label('Duplicate')->icon('heroicon-o-document-duplicate')
                    ->visible(fn ($record) => auth()->user()->can('create', Form::class) && auth()->user()->can('view', $record))
                    ->form([Forms\Components\TextInput::make('name')->label('New form name')->required()->maxLength(255)->default(fn ($record) => $record->name.' (Copy)')])
                    ->action(function ($record, array $data) {
                        Gate::authorize('create', Form::class);
                        Gate::authorize('view', $record);
                        $copy = app(EvaluationTemplates::class)->duplicate($record, filament()->getTenant(), $data['name']);

                        return redirect(static::getUrl('edit', ['record' => $copy]));
                    }),
                Tables\Actions\Action::make('build')
                    ->label('Build')
                    ->icon('heroicon-o-squares-plus')
                    ->color('primary')
                    ->url(fn ($record) => route('filament.admin.pages.forms.{record}.builder', [
                        'tenant' => filament()->getTenant(),
                        'record' => $record->id,
                    ])),
                Tables\Actions\Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->color('warning')
                    ->modalHeading('Form Preview')->modalWidth('7xl')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn ($record) => view('reports.preview-frame', ['url' => route('form.preview', $record), 'title' => 'Evaluation form preview', 'hint' => 'Preview the evaluator form. Submissions are disabled.'])),
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
        $canEditFields = auth()->user()?->can('update_form') ?? false;

        // Without update_form, users can only view submissions, not edit form fields.
        if (! $canEditFields) {
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
