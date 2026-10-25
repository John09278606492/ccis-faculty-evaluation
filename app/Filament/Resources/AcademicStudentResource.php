<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AcademicStudentResource\Pages;
use App\Models\Semester;
use App\Models\StudentAcademicRecord;
use App\Models\StudentSectionMembership;
use App\Models\User;
use App\Services\AcademicPeriod;
use App\Services\AcademicStudents;
use App\Services\StudentDeletion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AcademicStudentResource extends Resource
{
    protected static ?string $model = User::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $slug = 'academic-students';

    protected static ?string $navigationLabel = 'Student Master List';

    protected static ?string $modelLabel = 'Student';

    protected static ?string $navigationGroup = 'Academic Management';

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        $user = auth()->user();
        $campus = filament()->getTenant();

        return $user && $campus && ! $user->isStudent() && $user->canAccessTenant($campus) && $user->can('view_academic_students');
    }

    public static function canCreate(): bool
    {
        return static::canViewAny() && auth()->user()->can('manage_academic_students');
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny() && auth()->user()->can('manage_academic_students') && static::getEloquentQuery()->whereKey($record->id)->exists();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny() && app(StudentDeletion::class)->allowed(auth()->user(), filament()->getTenant(), $record);
    }

    public static function canDeleteAny(): bool
    {
        return static::canCreate() && auth()->user()->can('delete_academic_students');
    }

    public static function getEloquentQuery(): Builder
    {
        $campus = filament()->getTenant();
        $periods = Semester::whereHas('schoolYear', fn ($q) => $q->where('campus_id', $campus->id))->select('id');

        return app(AcademicStudents::class)->students(auth()->user(), $campus)->with(['academicRecords' => fn ($q) => $q->whereIn('semester_id', $periods)]);
    }

    public static function selectedPeriod($livewire): int
    {
        $campus = filament()->getTenant();
        $period = $livewire->tableFilters['semester']['value'] ?? AcademicPeriod::current($campus);

        return $period ? AcademicPeriod::validate($campus, $period) : 0;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Student Information')->description('Evaluation uses the student ID. Existing sign-in accounts and roles are preserved.')
                ->schema([
                    Forms\Components\TextInput::make('student_id')->label('Student ID')->required()->maxLength(50),
                    Forms\Components\TextInput::make('first_name')->required()->maxLength(100),
                    Forms\Components\TextInput::make('middle_name')->maxLength(100),
                    Forms\Components\TextInput::make('last_name')->required()->maxLength(100),
                    Forms\Components\TextInput::make('email')->email()->maxLength(255)->helperText('Optional. Evaluation entry uses the student ID.'),
                ])->columns(2),
            Forms\Components\Section::make('Academic Placement')->schema([
                Forms\Components\Select::make('semester_id')->label('Academic Period')->required()->live()
                    ->options(fn () => AcademicPeriod::options(filament()->getTenant()))
                    ->default(fn () => AcademicPeriod::current(filament()->getTenant()))
                    ->afterStateUpdated(function ($state, Forms\Set $set, ?User $record) {
                        $set('sections', $record && $state ? app(AcademicStudents::class)->sections($record, AcademicPeriod::validate(filament()->getTenant(), $state)) : []);
                    }),

                Forms\Components\Select::make('student_department_id')->label('Department')->required()->searchable()
                    ->options(fn () => app(AcademicStudents::class)->departments(auth()->user(), filament()->getTenant())->pluck('name', 'id')),
                Forms\Components\TagsInput::make('sections')->label('Sections')->placeholder('Add a section, e.g. BSIT 3A')
                    ->suggestions(fn (Forms\Get $get) => array_values(array_unique(array_map(fn ($key) => rawurldecode(explode('~', $key, 3)[2]), array_keys(app(AcademicStudents::class)->sectionOptions(auth()->user(), filament()->getTenant(), $get('semester_id') ? (int) $get('semester_id') : null))))))
                    ->helperText('Press Enter after each section. Add all sections attended by an irregular student. The year is part of the section name.'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('student_id')->label('Student ID')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('name')->searchable(['name', 'first_name', 'last_name']),
            Tables\Columns\TextColumn::make('studentDepartment.code')->label('Department')->badge()->default('Not assigned'),
            Tables\Columns\TextColumn::make('master_list_program')->label('Degree Program')->badge()
                ->state(fn (User $record, $livewire) => $record->academicRecords->firstWhere('semester_id', static::selectedPeriod($livewire))?->program)
                ->description(function (User $record, $livewire) {
                    $data = $record->academicRecords->firstWhere('semester_id', static::selectedPeriod($livewire));

                    return $data ? ($data->reported_year_level ? 'Reported year '.$data->reported_year_level.' · ' : '').($data->total_units !== null ? $data->total_units.' units' : '') : null;
                }),
            Tables\Columns\TextColumn::make('period_sections')->label('Sections in selected period')->badge()->state(fn (User $record, $livewire) => app(AcademicStudents::class)->sections($record, static::selectedPeriod($livewire))),
        ])->filters([
            Tables\Filters\SelectFilter::make('student_department_id')->label('Department')
                ->options(fn () => app(AcademicStudents::class)->departments(auth()->user(), filament()->getTenant())->pluck('name', 'id')),
            Tables\Filters\SelectFilter::make('semester')->label('Academic Period')
                ->options(fn () => AcademicPeriod::options(filament()->getTenant()))
                ->default(fn () => AcademicPeriod::current(filament()->getTenant()))
                ->query(function ($query, $livewire) {
                    static::selectedPeriod($livewire);

                    return $query;
                }),
            Tables\Filters\SelectFilter::make('section')->label('Class Section')
                ->options(fn ($livewire) => StudentSectionMembership::whereIn('user_id', static::getEloquentQuery()->select('users.id'))->where('semester_id', static::selectedPeriod($livewire))->distinct()->pluck('section', 'section'))
                ->query(fn ($query, array $data, $livewire) => $query->when($data['value'] ?? null, fn ($query, $section) => $query->whereHas('studentSectionMemberships', fn ($q) => $q->where('section', $section)->where('semester_id', static::selectedPeriod($livewire))))),
            Tables\Filters\SelectFilter::make('program')->label('Degree Program')
                ->options(fn ($livewire) => StudentAcademicRecord::whereIn('user_id', static::getEloquentQuery()->select('users.id'))->where('semester_id', static::selectedPeriod($livewire))->whereNotNull('program')->distinct()->orderBy('program')->pluck('program', 'program'))
                ->query(fn ($query, array $data, $livewire) => $query->when($data['value'] ?? null, fn ($q, $program) => $q->whereHas('academicRecords', fn ($q) => $q->where('program', $program)->where('semester_id', static::selectedPeriod($livewire))))),
        ], layout: FiltersLayout::AboveContent)->filtersFormColumns(['default' => 1, 'md' => 2])
            ->actions([
                Tables\Actions\Action::make('enrolledSubjects')->label('Enrolled Subjects')->icon('heroicon-o-academic-cap')->modalHeading('Campus Master List Record')->modalWidth('4xl')
                    ->modalContent(fn (User $record, $livewire) => view('filament.forms.student-master-list-record', ['student' => $record, 'period' => static::selectedPeriod($livewire)]))
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()->modalDescription(StudentDeletion::NOTICE)
                    ->using(fn (User $record) => app(StudentDeletion::class)->delete(auth()->user(), filament()->getTenant(), [$record->id]) > 0),
            ])->bulkActions([
                Tables\Actions\DeleteBulkAction::make()->modalDescription(StudentDeletion::NOTICE)
                    ->action(function ($records, Tables\Actions\DeleteBulkAction $action) {
                        app(StudentDeletion::class)->delete(auth()->user(), filament()->getTenant(), $records->modelKeys());
                        $action->success();
                    })->deselectRecordsAfterCompletion(),
            ])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAcademicStudents::route('/'), 'create' => Pages\CreateAcademicStudent::route('/create'), 'edit' => Pages\EditAcademicStudent::route('/{record}/edit')];
    }
}
