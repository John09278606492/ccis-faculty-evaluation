<?php

namespace App\Filament\Pages;

use App\Models\Department;
use App\Models\FormSubmission;
use App\Models\Semester;
use App\Services\AcademicPeriod;
use App\Services\BackgroundTasks;
use App\Services\ReportService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class DepartmentReport extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string $view = 'filament.pages.department-report';

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Evaluation Reports';

    protected static ?string $navigationLabel = 'Department Summary';

    protected static ?string $title = 'Department Summary';

    protected static ?int $navigationSort = 3;

    public ?string $selectedSemester = null;

    private array $summaryCache = [];

    private function summary(Department $department): array
    {
        $key = $department->id.':'.$this->selectedSemester;

        return $this->summaryCache[$key] ??= app(ReportService::class)->getDepartmentSummary($department, $this->selectedSemester ? (int) $this->selectedSemester : null);
    }

    /**
     * Only show to users who can view departments — matches the same check
     * mount() enforces, so the nav link never shows up just to 403 on click.
     */
    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();

        return $user && ($user->isSuperAdmin() || $user->can('view_any_department'));
    }

    public static function canAccess(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->selectedSemester = (string) AcademicPeriod::current(Filament::getTenant());
    }

    /**
     * Filament select form for semester filter.
     */
    public function semesterFilterForm(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('selectedSemester')
                    ->label('Semester')
                    ->options(function () {
                        $tenant = Filament::getTenant();
                        $query = Semester::query()
                            ->with('schoolYear')
                            ->orderByDesc('start_date');
                        if ($tenant) {
                            $query->whereHas('schoolYear', fn ($q) => $q->where('campus_id', $tenant->id));
                        }

                        return $query->get()->mapWithKeys(fn ($s) => [
                            $s->id => ($s->schoolYear?->name ?? '').' — '.$s->name,
                        ])->toArray();
                    })
                    ->placeholder('All Semesters')
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function ($state) {
                        if ($state) {
                            AcademicPeriod::select(Filament::getTenant(), (int) $state);
                        }
                        $this->resetTable();
                    }),
            ])
            ->columns(3)
            ->statePath(null);
    }

    protected function getForms(): array
    {
        return array_merge(parent::getForms(), [
            'semesterFilterForm',
        ]);
    }

    public function table(Table $table): Table
    {
        $service = app(ReportService::class);

        return $table
            ->query(function () {
                $tenant = Filament::getTenant();
                $query = Department::where('is_active', true)->orderBy('name');
                if ($tenant) {
                    $query->where('campus_id', $tenant->id);
                }

                return $query;
            })
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Department')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('evaluatees_count')
                    ->label('Evaluatees')
                    ->getStateUsing(function (Department $record) {
                        $summary = $this->summary($record);

                        return $summary['total_evaluatees'];
                    })
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('submissions_count')
                    ->label('Submissions')
                    ->getStateUsing(function (Department $record) {
                        $summary = $this->summary($record);

                        return $summary['total_submissions'];
                    })
                    ->badge()
                    ->color('info')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('set_rating')->label('SET / 5')
                    ->state(fn (Department $record) => $this->summary($record)['instruments']['SET']['mean_5'] ?? null)->numeric(2)->placeholder('Pending'),
                Tables\Columns\TextColumn::make('sef_rating')->label('SEF / 5')
                    ->state(fn (Department $record) => $this->summary($record)['instruments']['SEF']['mean_5'] ?? null)->numeric(2)->placeholder('Pending'),

                Tables\Columns\TextColumn::make('completion')
                    ->label('Completion')
                    ->getStateUsing(function (Department $record) {
                        $summary = $this->summary($record);

                        return $summary['completion_rate'].'%';
                    })
                    ->alignCenter(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('export_csv')
                    ->label('Export CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(function () {
                        $campus = Filament::getTenant();
                        app(BackgroundTasks::class)->enqueue(auth()->user(), $campus, 'department-export', 'Export department summary',
                            ['ids' => Department::where('campus_id', $campus->id)->where('is_active', true)->pluck('id')->all(), 'semester_id' => $this->selectedSemester ? (int) $this->selectedSemester : null]);
                    }),
            ])
            ->striped()
            ->paginated(false);
    }

    /**
     * Get the score distribution for chart.
     */
    public function getDistributionChartDataProperty(): array
    {
        $tenant = Filament::getTenant();
        $service = app(ReportService::class);

        $coQuery = $service->offerings(['campus_id' => $tenant?->id, 'semester_id' => $this->selectedSemester, 'evaluation_type' => 'SET']);
        if ($this->selectedSemester) {
            $coQuery->where('semester_id', (int) $this->selectedSemester);
        }
        if ($tenant) {
            $coQuery->whereHas('semester.schoolYear', fn ($q) => $q->where('campus_id', $tenant->id));
        }
        $coIds = $coQuery->pluck('id');

        $distribution = $service->getScoreDistribution($coIds);

        $categories = ['Outstanding', 'Very Satisfactory', 'Satisfactory', 'Fair', 'Poor'];
        $colors = ['#10b981', '#3b82f6', '#f59e0b', '#f97316', '#ef4444'];

        return [
            'labels' => $categories,
            'data' => array_map(fn ($c) => $distribution[$c] ?? 0, $categories),
            'colors' => $colors,
        ];
    }

    /**
     * Get semester trend data for the line chart.
     */
    public function getSemesterTrendDataProperty(): array
    {
        $tenant = Filament::getTenant();
        $service = app(ReportService::class);

        $semQuery = Semester::query()->with('schoolYear')->orderBy('start_date');
        if ($tenant) {
            $semQuery->whereHas('schoolYear', fn ($q) => $q->where('campus_id', $tenant->id));
        }
        $semesters = $semQuery->limit(8)->get();

        if ($semesters->isEmpty()) {
            return ['labels' => [], 'data' => []];
        }

        $labels = [];
        $data = [];

        foreach ($semesters as $sem) {
            $labels[] = ($sem->schoolYear?->name ?? '').' '.$sem->name;

            $coQuery = $service->offerings(['campus_id' => $tenant?->id, 'semester_id' => $sem->id, 'evaluation_type' => 'SET']);

            if ($tenant) {
                $coQuery->whereHas('semester.schoolYear', fn ($q) => $q->where('campus_id', $tenant->id));
            }

            $coIds = $coQuery->pluck('id');
            if ($coIds->isEmpty()) {
                $data[] = null;

                continue;
            }

            $submissions = FormSubmission::whereIn('course_offering_id', $coIds)->whereHas('courseOffering', fn ($q) => $q->whereColumn('course_offerings.form_id', 'form_submissions.form_id'))->with('form.fields')->get();
            $scores = $submissions->map(fn ($s) => $service->calculateSubmissionScore($s))->filter();
            $data[] = $scores->isNotEmpty() ? round($scores->avg(), 2) : null;
        }

        return [
            'labels' => $labels,
            'data' => $data,
        ];
    }

    public static function getRoutePath(): string
    {
        return '/reports/departments';
    }

    public static function getSlug(): string
    {
        return 'reports/departments';
    }
}
