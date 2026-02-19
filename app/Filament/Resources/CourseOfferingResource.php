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

class CourseOfferingResource extends Resource
{
    protected static ?string $model = CourseOffering::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Course Management';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Course Offerings';

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
                Forms\Components\Section::make('Course Offering Details')
                    ->schema([
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

                        Forms\Components\Select::make('department_id')
                            ->label('Department')
                            ->options(function () {
                                $campusId = filament()->getTenant()?->id;
                                return Department::where('campus_id', $campusId)
                                    ->where('is_active', true)
                                    ->pluck('name', 'id');
                            })
                            ->required()
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn(callable $set) => $set('course_id', null) && $set('user_id', null))
                            ->dehydrated(false), // Don't save to database (helper field only)

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
                            ->required()
                            ->searchable()
                            ->visible(fn(callable $get) => filled($get('department_id'))),

                        Forms\Components\Select::make('user_id')
                            ->label('Instructor')
                            ->options(function (callable $get) {
                                $departmentId = $get('department_id');
                                if (!$departmentId) {
                                    return [];
                                }
                                return User::where('instructor_department_id', $departmentId)
                                    ->where('is_instructor', true)
                                    ->get()
                                    ->mapWithKeys(fn($u) => [$u->id => $u->name]);
                            })
                            ->required()
                            ->searchable()
                            ->visible(fn(callable $get) => filled($get('department_id'))),

                        Forms\Components\TextInput::make('section')
                            ->maxLength(20)
                            ->placeholder('e.g., A, B, 1'),

                        Forms\Components\Toggle::make('is_active')
                            ->default(true),
                    ])->columns(2),

                Forms\Components\Section::make('Instructor Evaluation')
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
                            ->placeholder('Select an evaluation form for students')
                            ->helperText('Students will use this form to evaluate the instructor'),
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
                    ->badge(),

                Tables\Columns\TextColumn::make('course.name')
                    ->label('Course Name')
                    ->wrap()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('instructor.name')
                    ->label('Instructor')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('section')
                    ->sortable(),

                Tables\Columns\TextColumn::make('students_count')
                    ->counts('students')
                    ->label('Students')
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
            ])
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
