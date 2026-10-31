<?php

namespace App\Filament\Pages;

use App\Models\AcademicSection;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Semester;
use App\Models\User;
use App\Services\AcademicPeriod;
use App\Services\AssignedDataAccess;
use App\Services\EvaluationSubmissionIdentity;
use App\Services\StudentEvaluationProgress as Progress;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

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

    public string $viewMode = 'students';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bySection')->label('By Section')->icon('heroicon-o-user-group')->visible(fn () => $this->viewMode !== 'sections')->action(fn () => $this->changeView('sections')),
            Action::make('byStudent')->label('By Student')->icon('heroicon-o-user')->visible(fn () => $this->viewMode !== 'students')->action(fn () => $this->changeView('students')),
        ];
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

    private function sectionProgress(AcademicSection $section): array
    {
        if ($this->sectionCache === []) {
            $records = $this->getTableRecords();
            $ids = collect(method_exists($records, 'items') ? $records->items() : $records)->pluck('id')->all();
            $this->sectionCache = app(Progress::class)->sectionSummaries(auth()->user(), filament()->getTenant(), $ids, $this->selectedDepartments());
        }

        return $this->sectionCache[$section->id];
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
            ->query(fn () => $this->viewMode === 'sections' ? $this->sectionQuery() : User::query()->where('is_student', true)
                ->whereHas('campuses', fn ($q) => $q->whereKey(filament()->getTenant()->id))
                ->when(! ($this->tableFilters['section']['value'] ?? null), fn ($q) => $q->whereHas('courseOfferings', fn ($q) => $this->scopedAssignments($q)))
                ->when($this->tableFilters['section']['value'] ?? null, fn ($q, $id) => $q->whereHas('studentSectionMemberships', fn ($q) => $q->where('academic_section_id', $id)->whereIn('academic_section_id', $this->sectionQuery(false)->select('academic_sections.id'))))
                ->with(['studentSectionMemberships' => fn ($q) => $q->whereIn('academic_section_id', $this->sectionQuery(false)->select('academic_sections.id'))]))
            ->columns($this->viewMode === 'sections' ? [
                Tables\Columns\TextColumn::make('name')->label('Section')->description(fn (AcademicSection $record) => $record->department?->code)->searchable()->wrap(),
                Tables\Columns\TextColumn::make('section_students')->label('Students')->state(fn (AcademicSection $record) => $this->sectionProgress($record)['students'])
                    ->description(fn (AcademicSection $record) => $this->sectionProgress($record)['unassigned_students'].' without assignments'),
                Tables\Columns\TextColumn::make('finished')->label('Finished all')->state(fn (AcademicSection $record) => $this->sectionProgress($record)['assigned_students'] ? $this->sectionProgress($record)['complete_students'].' / '.$this->sectionProgress($record)['assigned_students'] : 'Not assigned')
                    ->badge()->color(fn (AcademicSection $record) => $this->sectionProgress($record)['assigned_students'] ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('followup')->label('Needs follow-up')->state(fn (AcademicSection $record) => $this->sectionProgress($record)['followup_students'])->badge()
                    ->color(fn (AcademicSection $record) => $this->sectionProgress($record)['followup_students'] ? 'warning' : 'gray'),
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
                Tables\Filters\SelectFilter::make('completion')->label('Show')->placeholder('All students')
                    ->visible(fn () => $this->viewMode !== 'sections')
                    ->options(['pending' => 'Still has evaluations to do', 'finished' => 'Finished all evaluations'])
                    ->query(fn ($query, array $data) => match ($this->viewMode === 'sections' ? null : ($data['value'] ?? null)) {
                        'pending' => $query->whereKey($this->studentsByCompletion()[1]),
                        'finished' => $query->whereKey($this->studentsByCompletion()[0]),
                        default => $query,
                    }),
                Tables\Filters\SelectFilter::make('section')->label('Section')->searchable()->placeholder('All authorized sections')
                    ->options(fn () => $this->sectionQuery(false)->orderBy('name')->get()->mapWithKeys(fn ($s) => [$s->id => $s->department?->code.' · '.$s->name]))
                    ->query(fn ($query) => $query),
            ], layout: FiltersLayout::AboveContent)->filtersFormColumns(['default' => 1, 'md' => 2, 'xl' => 4])
            ->actions([
                Tables\Actions\Action::make('students')->label('View Students')->icon('heroicon-o-users')->visible(fn () => $this->viewMode === 'sections')
                    ->action(fn (AcademicSection $record) => $this->showSectionStudents($record->id)),
            ])->emptyStateHeading(fn () => $this->viewMode === 'sections' ? 'No sections in this scope' : (($this->tableFilters['section']['value'] ?? null) ? 'No students in this section' : 'No students with evaluation assignments'))
            ->emptyStateDescription(fn () => $this->viewMode === 'sections' || ($this->tableFilters['section']['value'] ?? null)
                ? 'Create sections and upload their student lists under Academic Management → Sections.'
                : 'Students appear after their teaching classes have evaluation forms assigned. Use By Section to review section rosters and assignment coverage.');
    }
}
