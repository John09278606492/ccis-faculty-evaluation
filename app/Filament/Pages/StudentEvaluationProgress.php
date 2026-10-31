<?php

namespace App\Filament\Pages;

use App\Models\AcademicSection;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\EvaluationReminder;
use App\Models\Semester;
use App\Models\User;
use App\Services\AcademicPeriod;
use App\Services\AcademicStudents;
use App\Services\AssignedDataAccess;
use App\Services\EvaluationReminders;
use App\Services\EvaluationSubmissionIdentity;
use App\Services\StudentEvaluationProgress as Progress;
use App\Settings\EmailReminderSetting;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

class StudentEvaluationProgress extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;

    protected static string $view = 'filament.pages.student-evaluation-progress';

    protected static ?string $navigationGroup = 'Faculty Evaluation';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $title = 'Student Progress';

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $campus = filament()->getTenant();

        return $user && $campus && ! $user->isStudent() && $user->canAccessTenant($campus)
            && $user->can('page_StudentEvaluationProgress')
            && app(Progress::class)->departmentIds($user, $campus) !== [];
    }

    private array $progressCache = [];

    private array $sectionCache = [];

    private ?array $finishedCache = null;

    /** [students who finished every assigned evaluation, students with at least one left] in the current scope. */
    private function studentsByCompletion(): array
    {
        return $this->finishedCache ??= (function () {
            $classes = $this->scopedAssignments(CourseOffering::query())->whereNotNull('form_id')->get(['id', 'form_id'])->keyBy('id');
            $identity = app(EvaluationSubmissionIdentity::class);
            $all = $pending = [];
            foreach (array_chunk($classes->keys()->all(), 300) as $ids) {
                $done = DB::table('form_submissions')->join('course_offerings', 'course_offerings.id', '=', 'form_submissions.course_offering_id')
                    ->whereIn('course_offerings.id', $ids)->whereColumn('form_submissions.form_id', 'course_offerings.form_id')
                    ->get(['form_submissions.submission_fingerprint', 'form_submissions.user_id', 'form_submissions.course_offering_id']);
                $fingerprints = $done->pluck('submission_fingerprint')->filter()->flip();
                $legacy = $done->filter(fn ($row) => $row->user_id)->mapWithKeys(fn ($row) => [$row->course_offering_id.':'.$row->user_id => true]);
                foreach (DB::table('course_offering_student')->whereIn('course_offering_id', $ids)->get(['course_offering_id', 'user_id']) as $row) {
                    $all[$row->user_id] = true;
                    if (! $fingerprints->has($identity->forUser($classes[$row->course_offering_id], $row->user_id)) && ! $legacy->has($row->course_offering_id.':'.$row->user_id)) {
                        $pending[$row->user_id] = true;
                    }
                }
            }

            return [array_keys(array_diff_key($all, $pending)), array_keys($pending)];
        })();
    }

    #[Url(as: 'view')]
    public string $viewMode = 'students';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bySection')->label('By Section')->icon('heroicon-o-user-group')->visible(fn () => $this->viewMode !== 'sections')->action(fn () => $this->changeView('sections')),
            Action::make('byStudent')->label('By Student')->icon('heroicon-o-user')->visible(fn () => $this->viewMode !== 'students')->action(fn () => $this->changeView('students')),
            Action::make('remindAll')->label('Remind students')->icon('heroicon-o-bell-alert')->color('warning')
                ->modalHeading('Remind students who have not finished')
                ->modalDescription(fn () => $this->reminderDescription($this->reminderTargets($this->allReminderStudents())))
                ->form($this->reminderForm())->modalSubmitActionLabel('Send reminders')
                ->action(fn (array $data) => $this->sendReminders($this->allReminderStudents(), $data)),
        ];
    }

    /** Every student in the current filters: all sections in scope (By Section) or the filtered student list (By Student). */
    private function allReminderStudents(): array
    {
        if ($this->viewMode === 'sections') {
            return $this->sectionStudents($this->sectionQuery()->pluck('academic_sections.id')->all());
        }

        return array_fill_keys($this->getFilteredTableQuery()->pluck('users.id')->all(), null);
    }

    /** @return array<int, int|null> student id => section id, only students this user may see */
    private function sectionStudents(array $sectionIds): array
    {
        $allowed = $this->sectionQuery(false)->whereKey($sectionIds)->pluck('academic_sections.id')->all();
        $rows = DB::table('student_section_memberships')->whereIn('academic_section_id', $allowed)
            ->whereIn('user_id', app(AcademicStudents::class)->students(auth()->user(), filament()->getTenant())->select('users.id'))
            ->get(['user_id', 'academic_section_id']);

        return $rows->mapWithKeys(fn ($row) => [$row->user_id => $row->academic_section_id])->all();
    }

    private array $reminderCache = [];

    /** @return array<int, int> student id => open evaluations left */
    private function reminderTargets(array $students): array
    {
        $key = md5(json_encode([array_keys($students), $this->selectedSemester(), $this->selectedDepartments()]));

        return $this->reminderCache[$key] ??= app(Progress::class)->openPendingCounts(filament()->getTenant(), array_keys($students),
            $this->selectedSemester(), $this->selectedDepartments());
    }

    private function reminderDescription(array $targets): string
    {
        $count = count($targets);
        $email = app(EvaluationReminders::class)->emailReady() ? '' : ' Email is not set up yet, so they will get a bell notice in the system only.';

        return $count === 0 ? 'Everyone here has finished their open evaluations. There is no one to remind.'
            : $count.' '.str('student')->plural($count).' still '.($count === 1 ? 'has' : 'have').' '.array_sum($targets).' open '.str('evaluation')->plural(array_sum($targets)).' to finish.'.$email;
    }

    private function reminderForm(): array
    {
        $templates = EvaluationReminders::TEMPLATES;
        $emailReady = app(EvaluationReminders::class)->emailReady();

        return [
            Forms\Components\Select::make('template')->label('Reminder')->options(collect($templates)->map(fn ($t) => $t['label']))
                ->default('grades')->selectablePlaceholder(false)->live()
                ->afterStateUpdated(function ($state, Forms\Set $set) use ($templates) {
                    $set('subject', $templates[$state]['subject'] ?? '');
                    $set('message', $templates[$state]['message'] ?? '');
                }),
            Forms\Components\TextInput::make('subject')->label('Title')->required()->maxLength(150)->default($templates['grades']['subject'])
                ->helperText('Shown as the email subject and the bell title. You can use {first_name} and {remaining}.'),
            Forms\Components\Textarea::make('message')->label('Message')->rows(3)->maxLength(1000)->default($templates['grades']['message']),
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\Toggle::make('bell')->label('Bell notice in the system')->default(true),
                Forms\Components\Toggle::make('email')->label('Email to their school address')->default($emailReady)->disabled(! $emailReady)
                    ->helperText($emailReady ? 'firstname.lastname@'.app(EmailReminderSetting::class)->student_domain : 'A super admin must set this up in Administration → Email Reminders.'),
            ]),
            Forms\Components\Select::make('skip_hours')->label('Skip students already reminded in the last')->selectablePlaceholder(false)
                ->options([0 => 'Do not skip anyone', 6 => '6 hours', 12 => '12 hours', 24 => '24 hours', 72 => '3 days'])->default(12),
        ];
    }

    private function sendReminders(array $students, array $data): void
    {
        abort_unless(static::canAccess(), 403);
        $targets = $this->reminderTargets($students);
        if ($targets === []) {
            Notification::make()->info()->title('No one to remind')->body('These students have no open evaluations left.')->send();

            return;
        }
        if (! ($data['bell'] ?? false) && ! ($data['email'] ?? false)) {
            Notification::make()->warning()->title('Choose bell, email or both')->send();

            return;
        }
        $service = app(EvaluationReminders::class);
        $result = $service->send(auth()->user(), filament()->getTenant(), $targets, $data, array_filter($students));
        $sent = $result['students'] - $result['skipped'];
        Notification::make()->success()->title('Reminded '.$sent.' '.str('student')->plural($sent))->body($service->summary($result))->send();
        $this->lastReminded = null;
    }

    private ?array $lastReminded = null;

    private function lastReminded(AcademicSection $section): ?string
    {
        $this->lastReminded ??= EvaluationReminder::whereIn('academic_section_id', array_keys($this->allSectionProgress()))
            ->selectRaw('academic_section_id, max(created_at) as last')->groupBy('academic_section_id')->pluck('last', 'academic_section_id')->all();
        $last = $this->lastReminded[$section->id] ?? null;

        return $last ? 'Reminded '.Carbon::parse($last)->diffForHumans() : null;
    }

    public function changeView(string $mode): void
    {
        abort_unless(static::canAccess() && in_array($mode, ['students', 'sections'], true), 403);
        $this->viewMode = $mode;
        $this->progressCache = $this->sectionCache = [];
        // Rebuild columns for the new record type while retaining the common filters.
        $this->bootedInteractsWithTable();
        $this->resetTableSearch();
        $this->resetPage();
        $this->flushCachedTableRecords();
    }

    public function updatedTableFilters($value = null, ?string $key = null): void
    {
        if (in_array(explode('.', $key ?? '')[0], ['department', 'semester'], true)) {
            $this->tableFilters['section']['value'] = null;
        }
        if ($semester = $this->tableFilters['semester']['value'] ?? null) {
            AcademicPeriod::select(filament()->getTenant(), (int) $semester);
        }
        $this->progressCache = $this->sectionCache = [];
        $this->handleTableFilterUpdates();
    }

    private function selectedSemester(): ?int
    {
        $id = $this->tableFilters['semester']['value'] ?? AcademicPeriod::current(filament()->getTenant());

        return $id ? AcademicPeriod::validate(filament()->getTenant(), $id) : null;
    }

    private function sectionQuery(bool $applySectionFilter = true)
    {
        $departments = $this->selectedDepartments();

        return app(AssignedDataAccess::class)->apply(AcademicSection::query(), auth()->user(), filament()->getTenant())
            ->when($departments !== null, fn ($q) => $q->whereIn('department_id', $departments))
            ->when($this->selectedSemester(), fn ($q, $id) => $q->where('semester_id', $id))
            ->when($applySectionFilter && ($this->tableFilters['section']['value'] ?? null), fn ($q) => $q->whereKey($this->tableFilters['section']['value']))
            ->with('department');
    }

    /** Every section in scope, so the list can open with the sections furthest behind. */
    private function allSectionProgress(): array
    {
        if ($this->sectionCache === []) {
            $ids = $this->sectionQuery()->pluck('academic_sections.id')->all();
            $this->sectionCache = $ids ? app(Progress::class)->sectionSummaries(auth()->user(), filament()->getTenant(), $ids, $this->selectedDepartments()) : [];
        }

        return $this->sectionCache;
    }

    private function sectionProgress(AcademicSection $section): array
    {
        return $this->allSectionProgress()[$section->id] ?? ['students' => 0, 'assigned_students' => 0, 'complete_students' => 0, 'followup_students' => 0,
            'unassigned_students' => 0, 'total' => 0, 'completed' => 0, 'remaining' => 0, 'completion_rate' => null];
    }

    private function sectionsBehindFirst()
    {
        $order = collect($this->allSectionProgress())
            ->sortBy(fn ($p, $id) => sprintf('%d|%08.3f|%s', $p['completion_rate'] === null ? 1 : 0, $p['completion_rate'] ?? 0, $id))->keys();

        return $this->sectionQuery()->when($order->isNotEmpty(), fn ($q) => $q->orderByRaw('CASE academic_sections.id '
            .$order->map(fn ($id, $position) => 'WHEN '.(int) $id.' THEN '.(int) $position)->join(' ').' ELSE '.$order->count().' END'));
    }

    private function selectedReminderStudents(Collection $records): array
    {
        if ($this->viewMode === 'sections') {
            return $this->sectionStudents($records->modelKeys());
        }
        $ids = $this->getFilteredTableQuery()->whereKey($records->modelKeys())->pluck('users.id')->all();
        $sections = $this->sectionStudents($this->sectionQuery(false)->pluck('academic_sections.id')->all());

        return collect($ids)->mapWithKeys(fn ($id) => [$id => $sections[$id] ?? null])->all();
    }

    public function showSectionStudents(int $sectionId): void
    {
        abort_unless(static::canAccess(), 403);
        $section = $this->sectionQuery(false)->findOrFail($sectionId);
        $this->tableFilters['section']['value'] = $section->id;
        $this->changeView('students');
    }

    private function selectedDepartments(): ?array
    {
        $allowed = app(Progress::class)->departmentIds(auth()->user(), filament()->getTenant());
        $selected = $this->tableFilters['department']['value'] ?? null;
        if (! $selected) {
            return $allowed;
        }
        $valid = Department::where('campus_id', filament()->getTenant()->id)->whereKey($selected)->exists();

        return $valid && ($allowed === null || in_array((int) $selected, $allowed, true)) ? [(int) $selected] : [];
    }

    private function scopedAssignments($query)
    {
        return app(Progress::class)->scopeAssignments($query, filament()->getTenant(), $this->selectedDepartments())
            ->when($this->selectedSemester(), fn ($q, $id) => $q->where('semester_id', $id));
    }

    private function progress(User $student): array
    {
        $semesterId = $this->selectedSemester();
        $key = $student->id.':'.$semesterId.':'.json_encode($this->selectedDepartments());

        return $this->progressCache[$key] ??= app(Progress::class)->summary($student, filament()->getTenant(), $semesterId ? (int) $semesterId : null, $this->selectedDepartments());
    }

    private function progressStatus(User $student): string
    {
        $p = $this->progress($student);
        if ($p['total'] === 0) {
            return 'No assignments';
        }
        if ($p['remaining'] === 0) {
            return 'Complete';
        }
        $parts = [];
        foreach (['open_pending' => 'Needs follow-up', 'not_open' => 'Not yet open', 'closed_pending' => 'Closed / incomplete', 'unavailable' => 'Unavailable'] as $key => $label) {
            if ($p[$key] > 0) {
                $parts[] = $label.' ('.$p[$key].')';
            }
        }

        return implode(' · ', $parts);
    }

    public function table(Table $table): Table
    {
        return $table->heading(fn () => $this->viewMode === 'sections' ? 'Progress by section' : 'Progress by student')
            ->query(fn () => $this->viewMode === 'sections' ? $this->sectionsBehindFirst() : User::query()->where('is_student', true)
                ->whereHas('campuses', fn ($q) => $q->whereKey(filament()->getTenant()->id))
                ->when(! ($this->tableFilters['section']['value'] ?? null), fn ($q) => $q->whereHas('courseOfferings', fn ($q) => $this->scopedAssignments($q)))
                ->when($this->tableFilters['section']['value'] ?? null, fn ($q, $id) => $q->whereHas('studentSectionMemberships', fn ($q) => $q->where('academic_section_id', $id)->whereIn('academic_section_id', $this->sectionQuery(false)->select('academic_sections.id'))))
                ->with(['studentSectionMemberships' => fn ($q) => $q->whereIn('academic_section_id', $this->sectionQuery(false)->select('academic_sections.id'))]))
            ->columns($this->viewMode === 'sections' ? [
                Tables\Columns\TextColumn::make('name')->label('Section')->description(fn (AcademicSection $record) => collect([$record->department?->code, $this->lastReminded($record)])->filter()->join(' · '))->searchable()->wrap(),
                Tables\Columns\TextColumn::make('section_students')->label('Students')->state(fn (AcademicSection $record) => $this->sectionProgress($record)['students'])
                    ->description(fn (AcademicSection $record) => $this->sectionProgress($record)['unassigned_students'] ? $this->sectionProgress($record)['unassigned_students'].' without assignments' : null),
                Tables\Columns\TextColumn::make('finished')->label('Students finished')->state(fn (AcademicSection $record) => $this->sectionProgress($record)['assigned_students'] ? $this->sectionProgress($record)['complete_students'].' / '.$this->sectionProgress($record)['assigned_students'] : 'Not assigned')
                    ->description(fn (AcademicSection $record) => $this->sectionProgress($record)['assigned_students'] ? round(100 * $this->sectionProgress($record)['complete_students'] / $this->sectionProgress($record)['assigned_students']).'% of students' : null)
                    ->badge()->color(fn (AcademicSection $record) => $this->sectionProgress($record)['assigned_students'] ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('followup')->label('Students not finished')
                    ->state(fn (AcademicSection $record) => $this->sectionProgress($record)['assigned_students'] - $this->sectionProgress($record)['complete_students'])->badge()
                    ->color(fn (AcademicSection $record) => $this->sectionProgress($record)['assigned_students'] > $this->sectionProgress($record)['complete_students'] ? 'warning' : 'gray')
                    ->description(fn (AcademicSection $record) => $this->sectionProgress($record)['followup_students'] ? $this->sectionProgress($record)['followup_students'].' can still evaluate now' : null),
                Tables\Columns\TextColumn::make('completion')->label('Evaluations completed')->state(fn (AcademicSection $record) => $this->sectionProgress($record)['total'] ? $this->sectionProgress($record)['completed'].' / '.$this->sectionProgress($record)['total'] : 'No evaluation assignments')
                    ->description(fn (AcademicSection $record) => $this->sectionProgress($record)['completion_rate'] !== null ? $this->sectionProgress($record)['completion_rate'].'% complete · '.$this->sectionProgress($record)['remaining'].' remaining' : 'No completion rate yet'),
            ] : [
                Tables\Columns\TextColumn::make('student_id')->label('Student ID')->searchable(),
                Tables\Columns\TextColumn::make('name')->label('Student')->searchable(['name', 'first_name', 'last_name'])->wrap(),
                Tables\Columns\TextColumn::make('sections')->label('Section(s)')->state(fn (User $record) => $record->studentSectionMemberships->pluck('section')->unique()->all())->badge()->placeholder('Not assigned to a section'),
                Tables\Columns\TextColumn::make('progress')->label('Completed Evaluations')
                    ->state(fn (User $record) => $this->progress($record)['completed'].' out of '.$this->progress($record)['total'])->badge(),
                Tables\Columns\TextColumn::make('remaining')->label('Remaining')->state(fn (User $record) => $this->progress($record)['remaining']),
                Tables\Columns\TextColumn::make('status')->label('Follow-up Status')->badge()
                    ->state(fn (User $record) => $this->progressStatus($record))
                    ->color(fn (User $record) => $this->progress($record)['open_pending'] > 0 ? 'warning' : 'gray'),
            ])->filters([
                Tables\Filters\SelectFilter::make('department')->label('Department')
                    ->placeholder('All authorized departments')
                    ->options(function () {
                        $allowed = app(Progress::class)->departmentIds(auth()->user(), filament()->getTenant());

                        return Department::where('campus_id', filament()->getTenant()->id)->where('is_active', true)
                            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))->pluck('name', 'id');
                    })
                    ->default(function () {
                        $allowed = app(Progress::class)->departmentIds(auth()->user(), filament()->getTenant());
                        $home = auth()->user()->instructor_department_id;
                        if ($home && ($allowed === null || in_array($home, $allowed, true))
                            && Department::where('campus_id', filament()->getTenant()->id)->whereKey($home)->exists()) {
                            return $home;
                        }

                        return $allowed !== null && count($allowed) === 1 ? $allowed[0] : null;
                    })
                    ->query(fn ($query) => $query),
                Tables\Filters\SelectFilter::make('semester')->label('Semester / School Year')
                    ->default(fn () => AcademicPeriod::current(filament()->getTenant()))->selectablePlaceholder(false)
                    ->options(fn () => Semester::whereHas('schoolYear', fn ($q) => $q->where('campus_id', filament()->getTenant()->id))->with('schoolYear')->get()->mapWithKeys(fn ($s) => [$s->id => $s->name.' / '.$s->schoolYear->name]))
                    ->query(fn ($query) => $query),
                ...($this->viewMode === 'sections' ? [] : [Tables\Filters\SelectFilter::make('completion')->label('Show')->placeholder('All students')
                    ->options(['pending' => 'Still has evaluations to do', 'finished' => 'Finished all evaluations'])
                    ->query(fn ($query, array $data) => match ($this->viewMode === 'sections' ? null : ($data['value'] ?? null)) {
                        'pending' => $query->whereKey($this->studentsByCompletion()[1]),
                        'finished' => $query->whereKey($this->studentsByCompletion()[0]),
                        default => $query,
                    })]),
                Tables\Filters\SelectFilter::make('section')->label('Section')->searchable()->placeholder('All authorized sections')
                    ->options(fn () => $this->sectionQuery(false)->orderBy('name')->get()->mapWithKeys(fn ($s) => [$s->id => $s->department?->code.' · '.$s->name]))
                    ->query(fn ($query) => $query),
            ], layout: FiltersLayout::AboveContent)->filtersFormColumns(['default' => 1, 'md' => 2, 'xl' => 4])
            ->actions([
                Tables\Actions\Action::make('students')->label('Students')->tooltip('Open this section\'s student list')->icon('heroicon-o-users')->visible(fn () => $this->viewMode === 'sections')
                    ->action(fn (AcademicSection $record) => $this->showSectionStudents($record->id)),
                Tables\Actions\Action::make('remind')->label('Remind')->icon('heroicon-o-bell-alert')->color('warning')
                    ->visible(fn () => $this->viewMode === 'sections')
                    ->disabled(fn (AcademicSection $record) => $this->sectionProgress($record)['assigned_students'] === $this->sectionProgress($record)['complete_students'])
                    ->modalHeading(fn (AcademicSection $record) => 'Remind '.$record->name)
                    ->modalDescription(fn (AcademicSection $record) => $this->reminderDescription($this->reminderTargets($this->sectionStudents([$record->id]))))
                    ->form($this->reminderForm())->modalSubmitActionLabel('Send reminders')
                    ->action(fn (AcademicSection $record, array $data) => $this->sendReminders($this->sectionStudents([$record->id]), $data)),
            ])->bulkActions([
                Tables\Actions\BulkAction::make('remindSelected')->label('Remind selected')->icon('heroicon-o-bell-alert')->color('warning')
                    ->modalHeading('Remind the selected students')
                    ->modalDescription(fn (Collection $records) => $this->reminderDescription($this->reminderTargets($this->selectedReminderStudents($records))))
                    ->form($this->reminderForm())->modalSubmitActionLabel('Send reminders')->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records, array $data) => $this->sendReminders($this->selectedReminderStudents($records), $data)),
            ])->emptyStateHeading(fn () => $this->viewMode === 'sections' ? 'No sections in this scope' : (($this->tableFilters['section']['value'] ?? null) ? 'No students in this section' : 'No students with evaluation assignments'))
            ->emptyStateDescription(fn () => $this->viewMode === 'sections' || ($this->tableFilters['section']['value'] ?? null)
                ? 'Create sections and upload their student lists under Academic Management → Sections.'
                : 'Students appear after their teaching classes have evaluation forms assigned. Use By Section to review section rosters and assignment coverage.');
    }
}
