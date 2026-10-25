<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TeachingClassResource\Pages;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Services\AcademicPeriod;
use App\Services\AcademicStudents;
use App\Services\AssignedDataAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class TeachingClassResource extends CourseOfferingResource
{
    protected static ?string $slug = 'teaching-classes';

    protected static ?string $navigationLabel = 'Teaching Classes';

    protected static ?string $modelLabel = 'Teaching Class';

    protected static ?string $pluralModelLabel = 'Teaching Classes';

    protected static ?string $navigationGroup = 'Faculty Evaluation';

    protected static ?int $navigationSort = 1;

    public static function shouldRegisterNavigation(): bool
    {
        return parent::canViewAny();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNotNull('course_id')->where(fn ($q) => $q->whereDoesntHave('evaluationForm')->orWhereHas('evaluationForm', fn ($f) => $f->where('settings->annex', '!=', 'B')->orWhereNull('settings->annex')));
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Teaching class')->description('Enter academic data once. Reuse this class for evaluations and assessments.')->schema([
                Forms\Components\Select::make('semester_id')->label('Academic Period')->required()->options(fn () => AcademicPeriod::options(filament()->getTenant()))->default(fn () => AcademicPeriod::current(filament()->getTenant())),
                Forms\Components\Select::make('course_id')->label('Course / Subject')->required()->searchable()
                    ->options(fn () => app(AssignedDataAccess::class)->apply(Course::query(), auth()->user(), filament()->getTenant())->with('department')->get()->mapWithKeys(fn ($c) => [$c->id => $c->department->code.' · '.$c->code.' — '.$c->name])),
                Forms\Components\Select::make('user_id')->label('Teaching Faculty')->required()->searchable()
                    ->options(fn () => DB::table('users')->where('is_instructor', true)->whereIn('id', DB::table('campus_user')->select('user_id')->where('campus_id', filament()->getTenant()->id))->orderBy('name')->pluck('name', 'id'))
                    ->helperText('Includes visiting faculty from other departments in this campus.'),
                Forms\Components\TextInput::make('section')->label('Class reference (optional)')->placeholder('Optional reference')->maxLength(20)
                    ->helperText('This is a label only. Choose the actual student sections in Assign Evaluators by Section.'),
                Forms\Components\Toggle::make('is_active')->default(true),
            ])->columns(2),
            Forms\Components\Placeholder::make('sectionGuide')->hiddenLabel()->content('Student lists are managed in Academic Management → Sections. After saving this class, open Evaluators → Assign Evaluators by Section. Changing the faculty, subject or period before evaluations are prepared requires assigning evaluators again. Use a new class once evaluations have been prepared.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['course', 'evaluatee', 'semester.schoolYear'])->withCount('students'))
            ->columns([
                Tables\Columns\TextColumn::make('course.code')->label('Subject')->description(fn (CourseOffering $record) => $record->course?->name)->searchable(['code', 'name'])->wrap()->grow(),
                Tables\Columns\TextColumn::make('evaluatee.name')->label('Faculty')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('assigned_sections')->label('Assigned sections')->badge()->state(function (CourseOffering $record) {
                    if (! $record->students_count) {
                        return [];
                    }
                    if (($record->evaluator_selection['source'] ?? null) !== 'sections_v1') {
                        return ['Needs review'];
                    }

                    return array_map(fn ($key) => rawurldecode(explode('~', $key, 3)[2] ?? ''), $record->evaluator_selection['sections'] ?? []);
                })->placeholder('Not assigned'),
                Tables\Columns\TextColumn::make('students_count')->label('Evaluators')->numeric()->sortable()->badge()
                    ->description(fn (CourseOffering $record) => ! $record->is_active ? 'Inactive' : (! $record->students_count ? 'None assigned' : (($record->evaluator_selection['source'] ?? null) === 'sections_v1' ? 'Ready' : 'Review sections')))
                    ->color(fn (CourseOffering $record) => $record->is_active && $record->students_count && ($record->evaluator_selection['source'] ?? null) === 'sections_v1' ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('semester.name')->label('Academic period')->description(fn (CourseOffering $record) => $record->semester?->schoolYear?->name),
            ])->filters([
                Tables\Filters\SelectFilter::make('semester_id')->label('Academic period')->options(fn () => AcademicPeriod::options(filament()->getTenant()))->default(fn () => AcademicPeriod::current(filament()->getTenant())),
                Tables\Filters\SelectFilter::make('department')->label('Department')->options(fn () => app(AcademicStudents::class)->departments(auth()->user(), filament()->getTenant())->pluck('code', 'id'))
                    ->query(fn ($query, array $data) => $query->when($data['value'] ?? null, fn ($q, $id) => $q->whereHas('course', fn ($c) => $c->where('department_id', $id)))),
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
            ], layout: FiltersLayout::AboveContent)->filtersFormColumns(3)->actions([
                Tables\Actions\Action::make('assignEvaluators')->label('Assign sections')->icon('heroicon-o-user-group')
                    ->visible(fn (CourseOffering $record) => auth()->user()->can('manageEvaluators', $record))
                    ->url(fn (CourseOffering $record) => static::getUrl('edit', ['record' => $record, 'activeRelationManager' => '0'])),
                Tables\Actions\EditAction::make()->label('Class details')->iconButton()->tooltip('Edit class details'),
            ])->recordUrl(fn (CourseOffering $record) => auth()->user()->can('update', $record) ? static::getUrl('edit', ['record' => $record, 'activeRelationManager' => '0']) : null)
            ->defaultSort('id', 'desc')->emptyStateHeading('No teaching classes yet')->emptyStateDescription('Upload a section student list, then add the faculty member and subject here.');
    }

    public static function getRelations(): array
    {
        return [CourseOfferingResource\RelationManagers\StudentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListTeachingClasses::route('/'), 'create' => Pages\CreateTeachingClass::route('/create'), 'edit' => Pages\EditTeachingClass::route('/{record}/edit')];
    }
}
