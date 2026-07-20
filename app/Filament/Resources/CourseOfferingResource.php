<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CourseOfferingResource\Pages;
use App\Filament\Resources\CourseOfferingResource\RelationManagers;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Form as FormModel;
use App\Models\User;
use App\Models\Semester;
use App\Models\SchoolYear;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
    use Filament\Tables\Enums\FiltersLayout;

class CourseOfferingResource extends Resource
{
    protected static ?string $model = CourseOffering::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Evaluations';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Evaluation Assignments';

    protected static ?string $modelLabel = 'Evaluation Assignment';

    protected static ?string $pluralModelLabel = 'Evaluation Assignments';

    // Disable automatic tenancy scoping - we'll handle it manually
    protected static bool $isScopedToTenant = false;

    /**
     * Manually scope the query to the current tenant's course offerings.
     */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $campusId = filament()->getTenant()?->id;

        return parent::getEloquentQuery()
            ->whereHas('semester.schoolYear', fn($q) => $q->where('campus_id', $campusId));
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Assignment Details')
                    ->schema([
                        Forms\Components\Toggle::make('is_instructor_evaluation')
                            ->label('Evaluatee is an instructor')
                            ->helperText('Enable if the person being evaluated is an instructor assigned to a course. Disable for evaluating non-teaching staff or other personnel.')
                            ->default(true)
                            ->live()
                            ->dehydrated(false) // Not saved to DB
                            ->afterStateHydrated(function ($component, $record) {
                                // On edit, set the toggle based on whether a course is assigned
                                if ($record) {
                                    $component->state($record->course_id !== null);
                                }
                            })
                            ->afterStateUpdated(function ($state, callable $set) {
                                if (!$state) {
                                    // Clear instructor-specific fields
                                    $set('department_id', null);
                                    $set('course_id', null);
                                } else {
                                    $set('user_id', null);
                                }
                            })
                            ->columnSpanFull(),

                        Forms\Components\Select::make('semester_id')
                            ->label('Semester')
                            ->options(function () {
                                $campusId = filament()->getTenant()?->id;
                                return Semester::whereHas('schoolYear', fn($q) => $q->where('campus_id', $campusId))
                                    ->with('schoolYear')
                                    ->get()
                                    ->mapWithKeys(fn($s) => [$s->id => "{$s->schoolYear->name} - {$s->name}"]);
                            })
                            ->required()
                            ->searchable(),

                        // --- Instructor mode fields ---
                        Forms\Components\Select::make('department_id')
                            ->label('Department')
                            ->options(function () {
                                $campusId = filament()->getTenant()?->id;
                                return Department::where('campus_id', $campusId)
                                    ->where('is_active', true)
                                    ->pluck('name', 'id');
                            })
                            ->required(fn (callable $get) => $get('is_instructor_evaluation'))
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn(callable $set) => $set('course_id', null) && $set('user_id', null))
                            ->dehydrated(false) // Don't save to database (helper field only)
                            ->visible(fn (callable $get) => $get('is_instructor_evaluation')),

                        Forms\Components\Select::make('course_id')
                            ->label('Course')
                            ->options(function (callable $get) {
                                $departmentId = $get('department_id');
                                if (!$departmentId) {
                                    return [];
                                }
                                return Course::where('department_id', $departmentId)
                                    ->where('is_active', true)
                                    ->get()
                                    ->mapWithKeys(fn($c) => [$c->id => "{$c->code} - {$c->name}"]);
                            })
                            ->required(fn (callable $get) => $get('is_instructor_evaluation'))
                            ->searchable()
                            ->visible(fn(callable $get) => $get('is_instructor_evaluation') && filled($get('department_id'))),

                        // Evaluatee select — changes behavior based on toggle
                        Forms\Components\Select::make('user_id')
                            ->label(fn (callable $get) => $get('is_instructor_evaluation') ? 'Evaluatee (Instructor)' : 'Evaluatee (Person to Evaluate)')
                            ->options(function (callable $get) {
                                if ($get('is_instructor_evaluation')) {
                                    // Instructor mode: filter by department
                                    $departmentId = $get('department_id');
                                    if (!$departmentId) {
                                        return [];
                                    }
                                    return User::where('instructor_department_id', $departmentId)
                                        ->get()
                                        ->mapWithKeys(fn($u) => [$u->id => $u->full_name]);
                                }

                                // General mode: all users in the tenant
                                $campusId = filament()->getTenant()?->id;
                                return User::when($campusId, fn ($q) => $q->whereHas('campuses', fn ($sq) => $sq->where('campuses.id', $campusId)))
                                    ->orderBy('first_name')
                                    ->get()
                                    ->mapWithKeys(fn($u) => [$u->id => $u->full_name . ($u->position ? " ({$u->position})" : '')]);
                            })
                            ->required()
                            ->searchable()
                            ->live()
                            ->visible(fn (callable $get) => !$get('is_instructor_evaluation') || filled($get('department_id')))
                            ->helperText(fn (callable $get) => !$get('is_instructor_evaluation') ? 'Select any person to evaluate — not limited to instructors' : null),

                        Forms\Components\TextInput::make('evaluatee_label')
                            ->label('Evaluatee Label')
                            ->default('Faculty')
                            ->placeholder('e.g., Faculty, Staff, Dean, Admin')
                            ->helperText('What to call the person being evaluated on the form')
                            ->maxLength(50),

                        Forms\Components\TextInput::make('section')
                            ->maxLength(20)
                            ->placeholder('e.g., A, B, 1')
                            ->visible(fn (callable $get) => $get('is_instructor_evaluation')),

                        Forms\Components\Toggle::make('is_active')
                            ->default(true),
                    ])->columns(2),

                Forms\Components\Section::make('Evaluation Settings')
                    ->schema([
                        Forms\Components\Select::make('form_id')
                            ->label('Evaluation Form')
                            ->options(function () {
                                $campusId = filament()->getTenant()?->id;
                                return FormModel::whereHas('campuses', fn($q) => $q->where('campuses.id', $campusId))
                                    ->where('is_active', true)
                                    ->pluck('name', 'id');
                            })
                            ->searchable()
                            ->placeholder('Select an evaluation form')
                            ->helperText('Evaluators will use this form to evaluate the person assigned'),
                    ])
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('semester.schoolYear.name')
                    ->label('School Year')
                    ->sortable(),

                Tables\Columns\TextColumn::make('semester.name')
                    ->label('Semester')
                    ->sortable(),

                Tables\Columns\TextColumn::make('course.code')
                    ->label('Course')
                    ->sortable()
                    ->searchable()
                    ->badge()
                    ->placeholder('N/A'),

                Tables\Columns\TextColumn::make('course.name')
                    ->label('Course Name')
                    ->wrap()
                    ->toggleable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('evaluatee.name')
                    ->label('Evaluatee')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('section')
                    ->sortable(),

                Tables\Columns\TextColumn::make('students_count')
                    ->counts('students')
                    ->label('Evaluators')
                    ->sortable(),

                Tables\Columns\TextColumn::make('ratings_count')
                    ->counts('ratings')
                    ->label('Ratings')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('evaluationForm.name')
                    ->label('Evaluation Form')
                    ->badge()
                    ->color('success')
                    ->default('Not Assigned')
                    ->sortable(),

                Tables\Columns\TextColumn::make('evaluation_submissions_count')
                    ->label('Evaluations')
                    ->getStateUsing(fn($record) => $record->evaluationForm
                        ? \App\Models\FormSubmission::where('form_id', $record->form_id)
                            ->where('course_offering_id', $record->id)
                            ->count()
                        : 0)
                    ->badge()
                    ->color('info'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('semester')
                    ->relationship('semester', 'name'),
                Tables\Filters\SelectFilter::make('course')
                    ->relationship('course', 'code'),
                Tables\Filters\TernaryFilter::make('is_active'),
                Tables\Filters\SelectFilter::make('form_id')
                    ->label('Evaluation Form')
                    ->options(fn() => FormModel::whereHas('campuses', fn($q) => $q->where('campuses.id', filament()->getTenant()?->id))->pluck('name', 'id'))
                    ->placeholder('All Forms'),
            ], layout: FiltersLayout::AboveContent)
            ->actions([
                Tables\Actions\Action::make('evaluationLink')
                    ->label('Get Link')
                    ->icon('heroicon-o-link')
                    ->color('success')
                    ->visible(fn($record) => $record->form_id !== null)
                    ->action(function ($record) {
                        $url = route('evaluate.course-offering', $record);
                        \Filament\Notifications\Notification::make()
                            ->title('Evaluation Link Copied!')
                            ->body($url)
                            ->success()
                            ->persistent()
                            ->send();
                    }),
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
            RelationManagers\StudentsRelationManager::class,
            RelationManagers\RatingsRelationManager::class,
            RelationManagers\EvaluationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCourseOfferings::route('/'),
            'create' => Pages\CreateCourseOffering::route('/create'),
            'edit' => Pages\EditCourseOffering::route('/{record}/edit'),
        ];
    }
}
