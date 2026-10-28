<?php

namespace App\Filament\Pages;

use App\Models\Department;
use App\Models\EvaluationCycle;
use App\Models\Form;
use App\Models\Semester;
use App\Models\User;
use App\Services\AcademicPeriod;
use App\Services\AssignedDataAccess;
use App\Services\BackgroundTasks;
use App\Services\EvaluationCycles;
use App\Services\EvaluationScoring;
use App\Services\EvaluationTally;
use App\Services\ReportService;
use App\Support\EvaluationType;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EvaluationResults extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static string $view = 'filament.pages.evaluation-results';

    protected static ?string $navigationLabel = 'Results & Reports';

    protected static ?string $title = 'Results & Reports';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationGroup = 'Faculty Evaluation';

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $campus = filament()->getTenant();

        return $user && $campus && ! $user->isStudent() && $user->canAccessTenant($campus) && $user->can('page_EvaluationResults');
    }

    private array $summaryCache = [];

    public function reportContext(): array
    {
        return ['campus_id' => filament()->getTenant()?->id,
            'semester_id' => $this->tableFilters['semester']['value'] ?? AcademicPeriod::current(filament()->getTenant()),
            'evaluation_cycle_id' => $this->tableFilters['cycle']['value'] ?? null,
            'department_id' => $this->tableFilters['department']['value'] ?? ($this->tableFilters['instructor_department_id']['value'] ?? null),
            'form_id' => $this->tableFilters['form']['value'] ?? null,
            'evaluation_type' => $this->tableFilters['instrument']['value'] ?? null];
    }

    public function updatedTableFilters(): void
    {
        $semester = $this->tableFilters['semester']['value'] ?? null;
        if ($semester) {
            AcademicPeriod::select(filament()->getTenant(), (int) $semester);
        }
        $cycle = $this->tableFilters['cycle']['value'] ?? null;
        if ($cycle && ! EvaluationCycle::where('campus_id', filament()->getTenant()->id)->where('semester_id', $semester)->whereKey($cycle)->exists()) {
            $this->tableFilters['cycle']['value'] = null;
        }
        $this->handleTableFilterUpdates();
    }

    public function facultySummary(User $faculty): array
    {
        $context = $this->reportContext();
        $key = $faculty->id.':'.json_encode($context);

        return $this->summaryCache[$key] ??= app(ReportService::class)->getEvaluateeSummary($faculty, null, null, $context);
    }

    protected function getTableQuery(): Builder
    {
        return User::query()->whereIn('id', app(ReportService::class)->offerings($this->reportContext())->select('user_id'))->with('instructorDepartment');
    }

    private function score(User $faculty, string $instrument): ?float
    {
        return $this->facultySummary($faculty)['instruments'][$instrument]['mean_5'] ?? null;
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => $this->getTableQuery())->columns([
            Tables\Columns\TextColumn::make('name')->label('Faculty')->searchable(['name', 'first_name', 'last_name'])->sortable(),
            Tables\Columns\TextColumn::make('instructorDepartment.code')->label('Home Department')->badge()->default('â€”'),
            Tables\Columns\TextColumn::make('courses_count')->label('Assignments')->state(fn ($record) => $this->facultySummary($record)['total_courses']),
            Tables\Columns\TextColumn::make('evaluations_count')->label('Responses')->state(fn ($record) => $this->facultySummary($record)['total_submissions'])
                ->description(fn ($record) => $this->facultySummary($record)['complete_responses'].' complete Â· '.$this->facultySummary($record)['incomplete_responses'].' incomplete'),
            Tables\Columns\TextColumn::make('set_rating')->label('SET / 5')->state(fn ($record) => $this->score($record, 'SET'))->numeric(2)->placeholder('Pending')
                ->description(fn ($record) => isset($this->facultySummary($record)['instruments']['SET']['percentage']) ? number_format($this->facultySummary($record)['instruments']['SET']['percentage'], 2).' / 100' : null),
            Tables\Columns\TextColumn::make('sef_rating')->label('SEF / 5')->state(fn ($record) => $this->score($record, 'SEF'))->numeric(2)->placeholder('Pending supervisor evaluation')
                ->description(fn ($record) => isset($this->facultySummary($record)['instruments']['SEF']['percentage']) ? number_format($this->facultySummary($record)['instruments']['SEF']['percentage'], 2).' / 100' : null),
            Tables\Columns\TextColumn::make('other_rating')->label('Selected Type / 5')
                ->state(fn ($record) => $this->facultySummary($record)['instruments'][$this->reportContext()['evaluation_type']]['mean_5'] ?? null)->numeric(2)->placeholder('Pending')
                ->visible(fn () => $this->reportContext()['evaluation_type'] && ! in_array($this->reportContext()['evaluation_type'], ['SET', 'SEF'], true)),
            Tables\Columns\TextColumn::make('completion')->label('Student Completion')->state(fn ($record) => $this->facultySummary($record)['completion_rate'].'%'),
            Tables\Columns\TextColumn::make('overall_score')->label('Single-instrument Mean / 5')->state(fn ($record) => $this->facultySummary($record)['avg_score'] !== null ? number_format($this->facultySummary($record)['avg_score'], 2) : null)->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            Tables\Filters\SelectFilter::make('semester')->label('Semester / School Year')
                ->default(fn () => AcademicPeriod::current(filament()->getTenant()))->selectablePlaceholder(false)
                ->options(fn () => Semester::whereHas('schoolYear', fn ($q) => $q->where('campus_id', filament()->getTenant()?->id))->with('schoolYear')->orderByDesc('start_date')->get()->mapWithKeys(fn ($s) => [$s->id => $s->name.' / '.$s->schoolYear->name]))
                ->query(fn ($query) => $query),
            Tables\Filters\SelectFilter::make('cycle')->label('Evaluation')
                ->options(fn () => app(EvaluationCycles::class)->query(auth()->user(), filament()->getTenant())->where('semester_id', $this->reportContext()['semester_id'])->pluck('name', 'id'))->query(fn ($query) => $query),
            Tables\Filters\SelectFilter::make('department')->label('Teaching Department')
                ->options(fn () => app(AssignedDataAccess::class)->apply(Department::query(), auth()->user(), filament()->getTenant())->pluck('name', 'id'))->query(fn ($query) => $query),
            Tables\Filters\SelectFilter::make('form')->label('Instrument Version')
                ->options(fn () => app(AssignedDataAccess::class)->apply(Form::query(), auth()->user(), filament()->getTenant())->pluck('name', 'id'))->query(fn ($query) => $query),
            Tables\Filters\SelectFilter::make('instrument')->label('Evaluation Type')->options(EvaluationType::LABELS)
                ->query(fn ($query) => $query),
        ], layout: FiltersLayout::AboveContent)->filtersFormColumns(['default' => 1, 'md' => 2, 'xl' => 4])
            ->actions([
                Tables\Actions\Action::make('view_details')->label('View Reports')->icon('heroicon-o-document-chart-bar')->modalHeading(fn ($record) => 'Reports â€” '.$record->name)
                    ->modalWidth('7xl')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(function ($record) {
                        $context = $this->reportContext();
                        $offering = app(ReportService::class)->offerings($context)->where('user_id', $record->id)->orderBy('id')->firstOrFail();
                        $filters = ['offering' => $offering, 'department' => $context['department_id'],
                            'form' => $context['form_id'], 'cycle' => $context['evaluation_cycle_id']];

                        return view('reports.faculty-report-browser', [
                            'instructor' => $record, 'courseOfferings' => $this->getInstructorCourseData($record),
                            'reportOptions' => [
                                'scores' => ['label' => 'Scores Report', 'url' => route('evaluation.tabular-report', [...$filters, 'kind' => 'scores', 'instrument' => $context['evaluation_type']])],
                                'tally' => ['label' => 'Tally Report', 'url' => route('evaluation.tabular-report', [...$filters, 'kind' => 'tally', 'instrument' => $context['evaluation_type']])],
                                'annex_c' => ['label' => 'Annex C â€” Faculty Evaluation Report', 'url' => route('evaluation.report', [...$filters, 'annex' => 'C'])],
                                'annex_d' => ['label' => 'Annex D â€” Development Acknowledgement', 'url' => route('evaluation.report', [...$filters, 'annex' => 'D'])],
                            ],
                        ]);
                    }),
            ])->headerActions([
                Tables\Actions\Action::make('export_csv')->label('Export Filtered Results')->icon('heroicon-o-arrow-down-tray')
                    ->action(fn () => $this->exportFilteredResults()),
            ])->defaultSort('name')->emptyStateHeading('No faculty assignments in this selection')
            ->emptyStateDescription('Choose another semester or department, or create an evaluation assignment.');
    }

    public function exportFilteredResults()
    {
        app(BackgroundTasks::class)->enqueue(auth()->user(), filament()->getTenant(), 'faculty-export', 'Export filtered faculty results',
            ['ids' => $this->getFilteredTableQuery()->pluck('users.id')->all(), 'context' => $this->reportContext()]);
    }

    private function getInstructorCourseData(User $faculty): array
    {
        $results = [];
        foreach ($this->facultySummary($faculty)['course_offerings'] as $co) {
            $form = $co->evaluationForm;
            $submissions = $co->formSubmissions->where('form_id', $co->form_id);
            $score = app(EvaluationScoring::class)->assignment($co, $submissions);
            $comments = app(ReportService::class)->getAggregatedComments(collect([$co->id]));
            $scale = [['value' => 5, 'label' => 'Outstanding', 'color' => '#16a34a'], ['value' => 4, 'label' => 'Very Satisfactory', 'color' => '#2563eb'], ['value' => 3, 'label' => 'Satisfactory', 'color' => '#ca8a04'], ['value' => 2, 'label' => 'Fair', 'color' => '#ea580c'], ['value' => 1, 'label' => 'Poor', 'color' => '#dc2626']];
            $results[] = ['course_code' => $co->course?->code ?? 'Faculty evaluation', 'course_name' => $co->course?->name ?? '', 'section' => $co->section ?: 'â€”',
                'semester' => $co->semester?->name, 'school_year' => $co->semester?->schoolYear?->name, 'submission_count' => $score['count'],
                'incomplete_count' => $score['incomplete_count'], 'overall_score' => $score['mean_5'], 'percentage' => $score['percentage'],
                'raw_total' => $score['mean_total'], 'maximum' => $score['maximum'], 'overall_rating' => $score['mean_5'] !== null ? app(ReportService::class)->getRatingLabel($score['mean_5']) : 'Pending',
                'rating_color' => '#4f46e5', 'field_averages' => $score['fields']->all(), 'section_scores' => $score['sections'], 'rating_scale' => $scale,
                'scoring_method' => 'complete_response', 'section_method' => 'average', 'score_suffix' => ' / 5', 'max_scale' => 5,
                'form_name' => $form?->name, 'evaluation_type' => $co->evaluation_type_label, 'tally' => app(EvaluationTally::class)->build($co, $submissions),
                'field_comments' => $comments['field_comments'], 'overall_comments' => $comments['overall_comments']];
        }

        return $results;
    }
}
