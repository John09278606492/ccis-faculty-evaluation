<?php

namespace App\Filament\Resources\CourseOfferingResource\RelationManagers;

use App\Filament\Concerns\HasEvaluatorSelectionDraft;
use App\Filament\Concerns\MatchesEnrolledEvaluators;
use App\Filament\Resources\SectionResource;
use App\Filament\Resources\TeachingClassResource\Pages\EditTeachingClass;
use App\Models\User;
use App\Services\AcademicStudents;
use App\Services\EvaluationSections;
use App\Services\FacultySupervisorEvaluators;
use App\Services\StudentAcademicRecords;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StudentsRelationManager extends RelationManager
{
    use HasEvaluatorSelectionDraft;
    use MatchesEnrolledEvaluators;

    protected static string $view = 'filament.resources.evaluator-list';

    protected static string $relationship = 'students';

    protected static ?string $title = 'Evaluators';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();
        $ownerRecord->loadMissing('semester.schoolYear.campus');
        $campus = $ownerRecord->campus;

        return $user && ! $user->is_student && $campus && $user->canAccessTenant($campus)
            && (! filament()->getTenant() || filament()->getTenant()->getKey() === $campus->getKey())
            && ($user->can('view', $ownerRecord) || static::managesClassList($ownerRecord));
    }

    protected static function managesClassList(Model $offering): bool
    {
        return auth()->user()?->can('manageEvaluators', $offering) ?? false;
    }

    protected function canAttach(): bool
    {
        return static::managesClassList($this->getOwnerRecord());
    }

    protected function canDetach(Model $record): bool
    {
        return $this->canAttach() && $this->getOwnerRecord()->students()->whereKey($record->getKey())->exists();
    }

    protected function canDetachAny(): bool
    {
        return $this->canAttach();
    }

    protected function authorizeRosterChange(array $ids = [], bool $detach = false): void
    {
        $offering = $this->getOwnerRecord()->fresh();
        $this->authorize('manageEvaluators', $offering);
        if ($ids === []) {
            return;
        }
        $ids = array_values(array_unique($ids));
        $eligible = $detach ? $offering->students() : User::where('is_student', true)
            ->whereHas('campuses', fn ($query) => $query->where('campuses.id', $offering->campus->id));
        abort_unless($eligible->whereIn('users.id', $ids)->count() === count($ids), 403, 'Only eligible evaluators in this campus may be assigned.');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('user_id')->label('Evaluator')
                ->options(function () {
                    $campusId = filament()->getTenant()?->id;

                    return User::whereHas('campuses', fn ($q) => $q->where('campus_id', $campusId))
                        ->where('is_student', true)->get()->mapWithKeys(fn ($u) => [$u->id => "{$u->student_id} - {$u->full_name}"]);
                })->required()->searchable(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Match enrolled students or choose saved sections. Assign student sections before final reporting.')
            ->emptyStateHeading('No evaluators assigned yet')
            ->emptyStateDescription(fn () => $this->getOwnerRecord()->evaluationForm?->isSupervisorInstrument()
                ? 'Supervisor evaluators are explicitly selected on the faculty teaching class.'
                : 'Use Match Enrolled Students, or upload a section list and choose Assign Sections.')
            ->modifyQueryUsing(fn ($query) => $query->with(['studentDepartment', 'studentSectionMemberships' => fn ($q) => $q->where('semester_id', $this->getOwnerRecord()->semester_id)]))
            ->columns([
                Tables\Columns\TextColumn::make('student_id')->label('ID')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('name')->label('Name')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('email')->label('Email')->sortable()->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('studentDepartment.code')->label('Department')->badge(),
                Tables\Columns\TextColumn::make('period_sections')->label('Sections')->state(fn (User $record) => $record->studentSectionMemberships->pluck('section')->all())->badge(),
                Tables\Columns\TextColumn::make('report_section')->label('Report Section')->state(fn (User $record) => app(EvaluationSections::class)->forStudent($this->getOwnerRecord(), $record) ?: 'Section pending')->badge(),
                Tables\Columns\TextColumn::make('pivot.enrolled_at')->label('Enrolled At')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])->filters([])->headerActions([
                $this->matchEnrolledAction(),
                $this->sectionAction(),
                Tables\Actions\Action::make('setSupervisors')->label('Supervisors')->icon('heroicon-o-user-group')->color('gray')
                    ->visible(fn () => $this->canAttach() && ! $this->getOwnerRecord()->evaluationForm?->isSupervisorInstrument())
                    ->modalHeading('Select Supervisor Evaluators')->modalSubmitActionLabel('Save Supervisors')
                    ->fillForm(fn () => ['supervisor_ids' => $this->getOwnerRecord()->supervisorEvaluators()->pluck('users.id')->all()])
                    ->form([
                        Forms\Components\Select::make('supervisor_ids')->label('Supervisors')->multiple()->searchable()->preload()
                            ->options(fn () => app(FacultySupervisorEvaluators::class)->candidates($this->getOwnerRecord())->pluck('name', 'id'))
                            ->helperText('Choose who will answer Annex B. Student sections can be completed separately.'),
                    ])->action(function (array $data) {
                        app(FacultySupervisorEvaluators::class)->save(auth()->user(), $this->getOwnerRecord(), $data['supervisor_ids'] ?? []);
                        Notification::make()->success()->title('Supervisor evaluators saved')->send();
                    }),
                ...(is_a($this->getPageClass(), EditTeachingClass::class, true) ? [] : [Tables\Actions\ActionGroup::make([
                    $this->masterListAction(),
                    Tables\Actions\AttachAction::make()
                        ->databaseTransaction()->after(fn () => $this->stopFollowingSectionUploads())
                        ->before(fn (array $data) => $this->authorizeRosterChange((array) ($data['recordId'] ?? [])))
                        ->preloadRecordSelect()->recordSelectOptionsQuery(function () {
                            $campusId = filament()->getTenant()?->id;

                            return User::whereHas('campuses', fn ($q) => $q->where('campus_id', $campusId))->where('is_student', true);
                        })->recordTitle(fn (User $record) => "{$record->student_id} - {$record->full_name}")
                        ->recordSelectSearchColumns(['student_id', 'name', 'email', 'first_name', 'last_name']),
                    Tables\Actions\Action::make('bulkAttach')->authorize(fn () => $this->canAttach())
                        ->label('Bulk Add Evaluators')->icon('heroicon-o-users')->color('success')->form([
                            Forms\Components\Select::make('student_ids')->label('Select Evaluators')->multiple()->searchable()->preload()
                                ->options(function () {
                                    $campusId = filament()->getTenant()?->id;
                                    $courseOfferingId = $this->ownerRecord->id;

                                    return User::whereHas('campuses', fn ($q) => $q->where('campus_id', $campusId))->where('is_student', true)
                                        ->whereDoesntHave('enrolledCourseOfferings', fn ($q) => $q->where('course_offering_id', $courseOfferingId))
                                        ->get()->mapWithKeys(fn ($u) => [$u->id => "{$u->student_id} - {$u->name}"]);
                                })->required()->helperText('Select people to assign as evaluators'),
                        ])->action(function (array $data) {
                            $this->authorizeRosterChange($data['student_ids']);
                            $studentIds = $data['student_ids'];
                            $courseOffering = $this->ownerRecord;
                            DB::transaction(function () use ($studentIds, $courseOffering) {
                                $attachData = [];
                                foreach ($studentIds as $studentId) {
                                    $attachData[$studentId] = ['enrolled_at' => Carbon::now()];
                                }
                                $courseOffering->students()->syncWithoutDetaching($attachData);
                                $this->stopFollowingSectionUploads();
                            });
                            $count = count($studentIds);
                            Notification::make()->title("Successfully added {$count} ".($count === 1 ? 'evaluator' : 'evaluators'))->success()->send();
                        }),
                ])->label('Other assignment options')->icon('heroicon-o-ellipsis-horizontal')->button()->color('gray')]),
            ])->actions([
                Tables\Actions\DetachAction::make()->databaseTransaction()->before(fn (User $record) => $this->authorizeRosterChange([$record->id], true))
                    ->after(fn () => $this->stopFollowingSectionUploads()),
            ])->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    $this->reportSectionAction(),
                    Tables\Actions\DetachBulkAction::make()->databaseTransaction()->before(fn ($records) => $this->authorizeRosterChange($records->modelKeys(), true))
                        ->after(fn () => $this->stopFollowingSectionUploads()),
                ]),
            ]);
    }

    private function stopFollowingSectionUploads(): void
    {
        $class = $this->getOwnerRecord()->fresh();
        if (! empty($class->evaluator_selection['auto_section_roster']) || ! empty($class->evaluator_selection['auto_enrolment'])) {
            $class->update(['evaluator_selection' => [...$class->evaluator_selection, 'auto_section_roster' => false, 'auto_enrolment' => false]]);
        }
    }

    private function sectionAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('assignSections')->label('Assign Sections')->icon('heroicon-o-rectangle-stack')->color('gray')
            ->extraAttributes(['data-guide' => 'assign-sections-action'])
            ->visible(fn () => $this->canAttach() && ! $this->getOwnerRecord()->evaluationForm?->isSupervisorInstrument())
            ->modalHeading('Assign Sections')->modalSubmitActionLabel('Save Evaluators')
            ->fillForm(function () {
                $saved = $this->getOwnerRecord()->evaluator_selection ?? [];
                $trusted = ($saved['source'] ?? null) === 'sections_v1';
                $include = $trusted ? ($saved['include'] ?? []) : [];
                $exclude = $trusted ? ($saved['exclude'] ?? []) : [];

                return ['sections' => $saved['sections'] ?? [], 'include' => $include, 'exclude' => $exclude,
                    'use_exceptions' => filled($include) || filled($exclude),
                    'supervisor_ids' => $this->getOwnerRecord()->supervisorEvaluators()->pluck('users.id')->all()];
            })
            ->steps([
                Forms\Components\Wizard\Step::make('Choose Evaluators')->schema([
                    Forms\Components\Placeholder::make('evaluatorTour')->hiddenLabel()->content(fn () => view('components.guided-help', ['context' => 'assign-evaluators', 'compact' => true])),
                    Forms\Components\Placeholder::make('classContext')->label('Teaching class')->content(function () {
                        $class = $this->getOwnerRecord();
                        $class->loadMissing('course', 'instructor', 'semester.schoolYear');

                        return $class->course?->code.' — '.$class->course?->name.' · '.$class->instructor?->name.' · '.$class->semester?->name.' / '.$class->semester?->schoolYear?->name;
                    }),
                    Forms\Components\Select::make('sections')->label('Sections')->multiple()->searchable()->required()->live()
                        ->extraFieldWrapperAttributes(['data-guide' => 'evaluator-sections'])
                        ->options(fn () => app(AcademicStudents::class)->sectionOptions(auth()->user(), $this->getOwnerRecord()->campus, $this->getOwnerRecord()->semester_id, true))
                        ->afterStateUpdated(function (Forms\Set $set) {
                            $set('use_exceptions', false);
                            $set('include', []);
                            $set('exclude', []);
                        })
                        ->rules([fn () => function (string $attribute, $value, \Closure $fail) {
                            try {
                                app(AcademicStudents::class)->sectionAssignmentPreview(auth()->user(), $this->getOwnerRecord(), (array) $value);
                            } catch (ValidationException $e) {
                                $fail($e->validator->errors()->first());
                            }
                        }])
                        ->helperText('Choose the sections that attend this class.'),
                    Forms\Components\Placeholder::make('sectionStudents')->label('Selected Students')->content(function (Forms\Get $get) {
                        $stats = app(AcademicStudents::class)->sectionStats(auth()->user(), $this->getOwnerRecord()->campus, $this->getOwnerRecord()->semester_id);
                        $chosen = collect($get('sections') ?? [])->map(fn ($key) => $stats[$key] ?? null)->filter();

                        return $chosen->isEmpty() ? 'Choose a section. Its student list must be uploaded and confirmed first.'
                            : $chosen->map(fn ($s) => $s['name'].': '.$s['students'].' students'.($s['students'] === 0 ? ' — upload and confirm its list first' : ''))->join(' · ');
                    }),
                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('manageSectionStudents')->label('Manage Sections')->color('gray')->icon('heroicon-o-arrow-up-tray')
                            ->visible(fn () => SectionResource::canViewAny())
                            ->url(function (Forms\Get $get) {
                                $stats = app(AcademicStudents::class)->sectionStats(auth()->user(), $this->getOwnerRecord()->campus, $this->getOwnerRecord()->semester_id);
                                $key = ($get('sections') ?? [])[0] ?? null;

                                return isset($stats[$key]) ? SectionResource::getUrl('view', ['record' => $stats[$key]['id'], 'activeRelationManager' => '0']) : SectionResource::getUrl();
                            }),
                    ]),
                    Forms\Components\Toggle::make('use_exceptions')->label('Add or exclude students')->live()->default(false)
                        ->extraFieldWrapperAttributes(['data-guide' => 'evaluator-exceptions'])
                        ->afterStateUpdated(function (Forms\Set $set) {
                            $set('include', []);
                            $set('exclude', []);
                        }),
                    Forms\Components\Section::make('Individual Students')->visible(fn (Forms\Get $get) => (bool) $get('use_exceptions'))->schema([
                        Forms\Components\Select::make('include')->label('Add Students')->multiple()->searchable()
                            ->getSearchResultsUsing(fn (string $search) => $this->exceptionOptions($search))
                            ->getOptionLabelsUsing(fn (array $values) => $this->exceptionLabels($values)),
                        Forms\Components\Select::make('exclude')->label('Exclude Students')->multiple()->searchable()
                            ->getSearchResultsUsing(fn (string $search) => $this->exceptionOptions($search))
                            ->getOptionLabelsUsing(fn (array $values) => $this->exceptionLabels($values))
                            ->helperText('Excluded students will not be assigned. Previous responses are kept.'),
                    ]),
                    Forms\Components\Section::make('Supervisors')->schema([
                        Forms\Components\Select::make('supervisor_ids')->label('Supervisors')->multiple()->searchable()->preload()
                            ->extraFieldWrapperAttributes(['data-guide' => 'evaluator-supervisors'])
                            ->options(fn () => app(FacultySupervisorEvaluators::class)->candidates($this->getOwnerRecord())->pluck('name', 'id'))
                            ->nestedRecursiveRules([fn () => Rule::in(app(FacultySupervisorEvaluators::class)->candidates($this->getOwnerRecord())->pluck('id')->all())])
                            ->helperText('Choose who will answer Annex B. At least one is needed before opening.'),
                    ]),
                ]),
                Forms\Components\Wizard\Step::make('Review')->schema([
                    Forms\Components\Placeholder::make('preview')->label('Unique evaluators')->content(function (Forms\Get $get) {
                        try {
                            $p = app(AcademicStudents::class)->sectionAssignmentPreview(auth()->user(), $this->getOwnerRecord(), $get('sections') ?? [], $get('use_exceptions') ? ($get('include') ?? []) : [], $get('use_exceptions') ? ($get('exclude') ?? []) : []);
                        } catch (ValidationException $e) {
                            return $e->validator->errors()->first();
                        }

                        return 'Final roster: '.$p['total'].' students. '.$p['added'].' added, '.$p['removed'].' removed, '.$p['excluded'].' excluded. Overlapping sections count each student once. Existing submitted responses remain preserved.';
                    }),
                    Forms\Components\Placeholder::make('supervisorPreview')->label('Annex B evaluators')->content(fn (Forms\Get $get) => app(FacultySupervisorEvaluators::class)->candidates($this->getOwnerRecord())->whereKey($get('supervisor_ids') ?? [])->pluck('name')->join(', ') ?: 'No supervisor evaluators selected.'),
                ]),
            ])->action(function (array $data) {
                try {
                    $count = DB::transaction(function () use ($data) {
                        $class = $this->getOwnerRecord()->fresh();
                        $selection = $class->evaluator_selection ?? [];
                        $include = ! empty($data['use_exceptions']) ? ($data['include'] ?? []) : [];
                        $exclude = ! empty($data['use_exceptions']) ? ($data['exclude'] ?? []) : [];
                        $followSections = ! empty($selection['auto_section_roster']) && ! $include && ! $exclude
                            && array_values($selection['sections'] ?? []) === array_values($data['sections'] ?? []);
                        $count = app(AcademicStudents::class)->assignSections(auth()->user(), $class, $data['sections'] ?? [], $include, $exclude);
                        if ($followSections) {
                            $class->refresh();
                            $class->update(['evaluator_selection' => [...$class->evaluator_selection, 'auto_section_roster' => true]]);
                        }
                        app(FacultySupervisorEvaluators::class)->save(auth()->user(), $this->getOwnerRecord(), $data['supervisor_ids'] ?? []);

                        return $count;
                    });
                } catch (ValidationException $e) {
                    $prefix = 'mountedTableActionsData.'.array_key_last($this->mountedTableActionsData).'.';
                    throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $key) => [$prefix.$key => $messages])->all());
                }
                $this->completeWorkspaceDraft();
                Notification::make()->success()->title("{$count} evaluators added or retained")->send();
            });
    }

    private function exceptionOptions(string $search): array
    {
        return app(AcademicStudents::class)->students(auth()->user(), $this->getOwnerRecord()->campus)
            ->where(fn ($q) => $q->where('student_id', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%'))
            ->limit(25)->get()->mapWithKeys(fn ($s) => [$s->id => $s->student_id.' - '.$s->name])->all();
    }

    private function exceptionLabels(array $ids): array
    {
        return app(AcademicStudents::class)->students(auth()->user(), $this->getOwnerRecord()->campus)->whereKey($ids)
            ->get()->mapWithKeys(fn ($s) => [$s->id => $s->student_id.' - '.$s->name])->all();
    }

    private function masterListAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('masterListSubjects')->label('Add from Master List Subjects')->icon('heroicon-o-academic-cap')
            ->visible(fn () => $this->canAttach() && ! $this->getOwnerRecord()->evaluationForm?->isSupervisorInstrument())
            ->modalWidth('4xl')->modalSubmitActionLabel('Add Reviewed Evaluators')->fillForm(function () {
                $offering = $this->getOwnerRecord();
                $offering->loadMissing('course');

                return ['subject_key' => app(StudentAcademicRecords::class)->preferredSubject(auth()->user(), $offering), 'programs' => [], 'years' => [], 'exclude' => []];
            })->steps([
                Forms\Components\Wizard\Step::make('Match subject and students')->schema([
                    Forms\Components\Placeholder::make('masterListInstructions')->content('Suggestions use actual subject enrolments in this class academic period and your authorized departments. The campus report does not identify sections or instructors: choose the correct subject description and program, then exclude students who do not attend this class. Existing evaluators will be kept.'),
                    Forms\Components\Select::make('subject_key')->label('Master list subject')->required()->live()->searchable()
                        ->options(fn () => app(StudentAcademicRecords::class)->subjectOptions(auth()->user(), $this->getOwnerRecord()))
                        ->afterStateUpdated(function (Forms\Set $set) {
                            $set('programs', []);
                            $set('years', []);
                            $set('exclude', []);
                        })->helperText('Only subjects matching this class subject code are offered. Different descriptions remain separate; no catalogue is overwritten.'),
                    Forms\Components\Select::make('programs')->label('Degree programs')->multiple()->live()
                        ->options(fn (Forms\Get $get) => app(StudentAcademicRecords::class)->cohortOptions(auth()->user(), $this->getOwnerRecord(), 'program', $get('subject_key')))
                        ->disabled(fn (Forms\Get $get) => ! $get('subject_key'))
                        ->afterStateUpdated(function (Forms\Set $set) {
                            $set('years', []);
                            $set('exclude', []);
                        })->helperText('Only programs with students enrolled in the selected subject this period appear. Leave empty to include all of these programs.'),
                    Forms\Components\Select::make('years')->label('Reported year in this academic period')->multiple()->live()
                        ->options(fn (Forms\Get $get) => app(StudentAcademicRecords::class)->cohortOptions(auth()->user(), $this->getOwnerRecord(), 'reported_year_level', $get('subject_key'), $get('programs') ?? []))
                        ->disabled(fn (Forms\Get $get) => ! $get('subject_key'))->afterStateUpdated(fn (Forms\Set $set) => $set('exclude', []))
                        ->helperText('Years are limited to students in the selected subject and programs.'),
                    Forms\Components\Select::make('exclude')->label('Do not add these students')->multiple()->searchable()
                        ->options(fn (Forms\Get $get) => $get('subject_key') ? app(StudentAcademicRecords::class)->candidates(auth()->user(), $this->getOwnerRecord(), $get('subject_key'), $get('programs') ?? [], $get('years') ?? [])->get()->mapWithKeys(fn ($s) => [$s->id => $s->student_id.' — '.$s->name]) : []),
                ]),
                Forms\Components\Wizard\Step::make('Review roster')->schema([
                    Forms\Components\Placeholder::make('masterListPreview')->content(function (Forms\Get $get) {
                        if (! $get('subject_key')) {
                            return 'Choose the matching master list subject first.';
                        }
                        $ids = app(StudentAcademicRecords::class)->candidates(auth()->user(), $this->getOwnerRecord(), $get('subject_key'), $get('programs') ?? [], $get('years') ?? [])->pluck('users.id')->diff($get('exclude') ?? []);
                        $current = $this->getOwnerRecord()->students()->pluck('users.id');

                        return $ids->count().' matching students; '.$ids->diff($current)->count().' will be added. Final roster: '.$current->merge($ids)->unique()->count().'. Existing evaluator assignments and submitted responses are preserved.';
                    }),
                    Forms\Components\Checkbox::make('confirmed')->label('I checked that the selected students attend this instructor’s class / section.')->accepted(),
                ]),
            ])->action(function (array $data) {
                $count = app(StudentAcademicRecords::class)->addEvaluators(auth()->user(), $this->getOwnerRecord()->fresh(), $data['subject_key'], $data['programs'] ?? [], $data['years'] ?? [], $data['exclude'] ?? []);
                Notification::make()->success()->title("{$count} matching evaluators added or retained")->send();
            });
    }
}
