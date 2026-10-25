<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EvaluationCycleResource\Pages;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\EvaluationCycle;
use App\Models\Form as Instrument;
use App\Services\AcademicPeriod;
use App\Services\AssignedDataAccess;
use App\Services\EvaluationCycles;
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

    protected static ?string $navigationLabel = 'Evaluation Setup';

    protected static ?string $modelLabel = 'Evaluation';

    protected static ?string $pluralModelLabel = 'Evaluation Setup';

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
        return $form->schema([
            Forms\Components\Section::make('1. Evaluation schedule')->description('The academic period identifies the semester. Opens and Closes control when evaluators may respond.')->schema([
                Forms\Components\TextInput::make('name')->label('Evaluation name')->required()->maxLength(255)->placeholder('CCIS Faculty Evaluation — First Semester'),
                Forms\Components\Select::make('department_id')->label('Department')->required()->live()
                    ->options(fn () => app(AssignedDataAccess::class)->apply(Department::query(), auth()->user(), filament()->getTenant())->pluck('name', 'id')),
                Forms\Components\Select::make('semester_id')->label('Academic Period')->required()->live()->options(fn () => AcademicPeriod::options(filament()->getTenant()))->default(fn () => AcademicPeriod::current(filament()->getTenant())),
                Forms\Components\DateTimePicker::make('starts_at')->label('Opens')->required(),
                Forms\Components\DateTimePicker::make('ends_at')->label('Closes')->required()->after('starts_at'),
            ])->columns(2),
            Forms\Components\Section::make('2. Evaluation forms')->description('Annex A is answered by students; Annex B is answered by the supervisors explicitly selected for each faculty. Published forms are protected.')->schema([
                Forms\Components\Select::make('student_form_id')->label('Annex A — Student Evaluation')->required()->searchable()
                    ->options(fn () => Instrument::whereHas('campuses', fn ($q) => $q->whereKey(filament()->getTenant()->id))->where('settings->annex', 'A')->whereNull('archived_at')->pluck('name', 'id')),
                Forms\Components\Select::make('supervisor_form_id')->label('Annex B — Supervisor Evaluation')->required()->searchable()
                    ->options(fn () => Instrument::whereHas('campuses', fn ($q) => $q->whereKey(filament()->getTenant()->id))->where('settings->annex', 'B')->whereNull('archived_at')->pluck('name', 'id')),
            ])->columns(2),
            Forms\Components\Section::make('3. Classes included')->description('Select the teaching classes prepared in Step 2. Their assigned section students are reused; no student list needs to be uploaded here.')->schema([
                Forms\Components\Select::make('assignment_ids')->label('Teaching classes')->multiple()->searchable()->preload()
                    ->options(fn (Forms\Get $get, ?EvaluationCycle $record) => CourseOffering::where('semester_id', $get('semester_id'))
                        ->whereHas('course', fn ($q) => $q->where('department_id', $get('department_id')))
                        ->where(fn ($q) => $q->whereNull('evaluation_cycle_id')->when($record, fn ($q) => $q->orWhere('evaluation_cycle_id', $record->id)))
                        ->with(['course', 'evaluatee'])->get()->mapWithKeys(fn ($a) => [$a->id => $a->display_name.' · '.$a->evaluatee->name]))
                    ->helperText('Select each teaching class once. Students may belong to several sections; each assignment still permits one response per student.'),
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
            Tables\Columns\TextColumn::make('assignments_count')->counts('assignments')->label('Assignments'),
            Tables\Columns\TextColumn::make('ends_at')->label('Closes')->dateTime(),
        ])->filters([
            Tables\Filters\SelectFilter::make('semester_id')->label('Academic Period')->options(fn () => AcademicPeriod::options(filament()->getTenant()))->default(fn () => AcademicPeriod::current(filament()->getTenant())),
            Tables\Filters\SelectFilter::make('status')->options(['draft' => 'Draft', 'published' => 'Published', 'closed' => 'Closed', 'archived' => 'Archived']),
        ], layout: FiltersLayout::AboveContent)->actions([Tables\Actions\EditAction::make()->label('Manage setup')]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListEvaluationCycles::route('/'), 'create' => Pages\CreateEvaluationCycle::route('/create'), 'edit' => Pages\EditEvaluationCycle::route('/{record}/edit')];
    }
}
