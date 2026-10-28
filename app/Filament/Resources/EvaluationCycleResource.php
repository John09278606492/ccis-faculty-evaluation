<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EvaluationCycleResource\Pages;
use App\Models\Department;
use App\Models\EvaluationCycle;
use App\Models\Form as Instrument;
use App\Services\AcademicPeriod;
use App\Services\AssignedDataAccess;
use App\Services\EvaluationCycles;
use App\Services\EvaluationSetupAutomation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EvaluationCycleResource extends Resource
{
    protected static ?string $model = EvaluationCycle::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationGroup = 'Faculty Evaluation';

    protected static ?string $navigationLabel = 'Evaluations';

    protected static ?string $modelLabel = 'Evaluation';

    protected static ?string $pluralModelLabel = 'Evaluations';

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return auth()->user() && filament()->getTenant() && app(EvaluationCycles::class)->allowed(auth()->user(), filament()->getTenant());
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny() && static::getEloquentQuery()->whereKey($record->id)->exists();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return app(EvaluationCycles::class)->query(auth()->user(), filament()->getTenant());
    }

    public static function form(Form $form): Form
    {
        $automation = app(EvaluationSetupAutomation::class);
        $plan = fn (Forms\Get $get, ?EvaluationCycle $record) => $automation->plan(auth()->user(), filament()->getTenant(), $get('department_id') ? (int) $get('department_id') : null, $get('semester_id') ? (int) $get('semester_id') : null, $record);
        $defaults = function (Forms\Get $get, Forms\Set $set) use ($automation) {
            $suggested = $automation->defaults(auth()->user(), filament()->getTenant(), $get('department_id') ? (int) $get('department_id') : null, $get('semester_id') ? (int) $get('semester_id') : null);
            if (! filled($get('name')) || $get('name') === $get('suggested_name')) {
                $set('name', $suggested['name'] ?? '');
            }
            $set('suggested_name', $suggested['name'] ?? null);
            foreach (['student_form_id', 'supervisor_form_id'] as $field) {
                $set($field, $suggested[$field] ?? null);
            }
            $set('class_selection_mode', 'prepared');
            $set('faculty_ids', []);
            $set('assignment_ids', []);
        };

        return $form->schema([
            Forms\Components\Hidden::make('suggested_name')->dehydrated(false),
            Forms\Components\Section::make('Details')->schema([
                Forms\Components\TextInput::make('name')->label('Evaluation name')->required()->maxLength(255)->placeholder('CCIS Faculty Evaluation — First Semester')->columnSpanFull(),
                Forms\Components\Select::make('department_id')->label('Department')->required()->live()
                    ->options(fn () => app(AssignedDataAccess::class)->apply(Department::query(), auth()->user(), filament()->getTenant())->where('is_active', true)->pluck('name', 'id'))
                    ->afterStateUpdated($defaults),
                Forms\Components\Select::make('semester_id')->label('Academic Period')->required()->live()->options(fn () => AcademicPeriod::options(filament()->getTenant()))->default(fn () => AcademicPeriod::current(filament()->getTenant()))
                    ->afterStateUpdated($defaults),
            ])->columns(2),
            Forms\Components\Section::make('Schedule')->schema([
                Forms\Components\DateTimePicker::make('starts_at')->label('Opens')->nullable()
                    ->helperText('Leave empty to start when you open the evaluation.'),
                Forms\Components\DateTimePicker::make('ends_at')->label('Closes')->nullable()
                    ->after(fn (Forms\Get $get) => filled($get('starts_at')) ? 'starts_at' : null)
                    ->helperText('Leave empty to keep accepting responses until you select Close Evaluation.'),
            ])->columns(2),
            Forms\Components\Section::make('Forms')->schema([
                Forms\Components\Select::make('student_form_id')->label('Annex A — Student Evaluation')->required()->searchable()
                    ->options(fn (Forms\Get $get) => Instrument::whereHas('campuses', fn ($q) => $q->whereKey(filament()->getTenant()->id))->where('settings->annex', 'A')->whereNull('archived_at')
                        ->where(fn ($q) => $q->whereDoesntHave('departments')->orWhereHas('departments', fn ($d) => $d->whereKey($get('department_id'))))->pluck('name', 'id')),
                Forms\Components\Select::make('supervisor_form_id')->label('Annex B — Supervisor Evaluation')->required()->searchable()
                    ->options(fn (Forms\Get $get) => Instrument::whereHas('campuses', fn ($q) => $q->whereKey(filament()->getTenant()->id))->where('settings->annex', 'B')->whereNull('archived_at')
                        ->where(fn ($q) => $q->whereDoesntHave('departments')->orWhereHas('departments', fn ($d) => $d->whereKey($get('department_id'))))->pluck('name', 'id')),
            ])->columns(2),
            Forms\Components\Section::make('Faculty and Classes')->schema([
                Forms\Components\Radio::make('class_selection_mode')->label('Include')->required()->live()->default('prepared')
                    ->options(['prepared' => 'All prepared classes in this department', 'faculty' => 'All prepared classes for selected faculty', 'manual' => 'Choose specific classes'])
                    ->helperText('Prepared classes already have students and any required sections. Their assigned supervisors are reused. Opening still requires your confirmation.'),
                Forms\Components\Select::make('faculty_ids')->label('Faculty')->multiple()->searchable()->preload()->live()
                    ->visible(fn (Forms\Get $get) => $get('class_selection_mode') === 'faculty')->required(fn (Forms\Get $get) => $get('class_selection_mode') === 'faculty')
                    ->options(fn (Forms\Get $get, ?EvaluationCycle $record) => $plan($get, $record)['prepared']->unique('user_id')->mapWithKeys(fn ($c) => [$c->user_id => $c->evaluatee?->name]))
                    ->helperText('Choose a faculty member once. All their prepared subjects and sections are included.'),
                Forms\Components\Placeholder::make('class_summary')->hiddenLabel()->content(function (Forms\Get $get, ?EvaluationCycle $record) use ($plan) {
                    $p = $plan($get, $record);
                    $mode = $get('class_selection_mode');
                    $selected = $mode === 'manual' ? $p['classes']->whereIn('id', $get('assignment_ids') ?? []) : ($mode === 'faculty' ? $p['prepared']->whereIn('user_id', $get('faculty_ids') ?? []) : $p['prepared']);

                    return view('filament.forms.evaluation-class-summary', ['classes' => $selected, 'excluded' => $p['excluded'], 'automatic' => $mode !== 'manual']);
                }),
                Forms\Components\Section::make('Specific classes')->collapsible()->collapsed()->visible(fn (Forms\Get $get) => $get('class_selection_mode') === 'manual')->schema([
                    Forms\Components\CheckboxList::make('assignment_ids')->label('Search by faculty, subject or section')->searchable()->bulkToggleable()->live()->columns(1)
                        ->options(fn (Forms\Get $get, ?EvaluationCycle $record) => $plan($get, $record)['classes']->mapWithKeys(fn ($c) => [$c->id => $c->evaluatee?->name.' · '.$c->display_name]))
                        ->extraAttributes(['style' => 'max-height:320px;overflow:auto;']),
                ]),
            ]),
        ])->disabled(fn (?EvaluationCycle $record) => $record && $record->status !== 'draft');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('name')->label('Evaluation')->searchable(),
            Tables\Columns\TextColumn::make('department.code')->label('Department')->badge(),
            Tables\Columns\TextColumn::make('semester.name')->label('Academic Period')->description(fn ($record) => $record->semester->schoolYear->name),
            Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                'published' => 'success', 'draft' => 'warning', default => 'gray'
            }),
            Tables\Columns\TextColumn::make('assignments_count')->counts('assignments')->label('Classes'),
            Tables\Columns\TextColumn::make('ends_at')->label('Closes')->dateTime()->placeholder('Manual closing'),
        ])->filters([
            Tables\Filters\SelectFilter::make('semester_id')->label('Academic Period')->options(fn () => AcademicPeriod::options(filament()->getTenant()))->default(fn () => AcademicPeriod::current(filament()->getTenant())),
            Tables\Filters\SelectFilter::make('status')->options(['draft' => 'Draft', 'published' => 'Published', 'closed' => 'Closed', 'archived' => 'Archived']),
        ], layout: FiltersLayout::AboveContent)->actions([Tables\Actions\EditAction::make()->label('Manage')]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListEvaluationCycles::route('/'), 'create' => Pages\CreateEvaluationCycle::route('/create'), 'edit' => Pages\EditEvaluationCycle::route('/{record}/edit')];
    }
}
