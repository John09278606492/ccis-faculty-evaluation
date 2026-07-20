<?php

namespace App\Filament\Pages;

use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\FormSubmission;
use App\Models\Semester;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DepartmentReport extends Page implements HasTable, HasForms
{
    use InteractsWithTable;
    use InteractsWithForms;

    protected static string $view = 'filament.pages.department-report';

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Evaluations';

    protected static ?string $navigationLabel = 'Department Summary';

    protected static ?string $title = 'Department Summary';

    protected static ?int $navigationSort = 51;

    public ?string $selectedSemester = null;

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
                            $query->whereHas('schoolYear', fn($q) => $q->where('campus_id', $tenant->id));
                        }

                        return $query->get()->mapWithKeys(fn($s) => [
                            $s->id => ($s->schoolYear?->name ?? '') . ' — ' . $s->name,
                        ])->toArray();
                    })
                    ->placeholder('All Semesters')
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(fn() => $this->resetTable()),
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
                    ->getStateUsing(function (Department $record) use ($service) {
                        $summary = $service->getDepartmentSummary($record, $this->selectedSemester ? (int) $this->selectedSemester : null);

                        return $summary['total_evaluatees'];
                    })
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('submissions_count')
                    ->label('Submissions')
                    ->getStateUsing(function (Department $record) use ($service) {
                        $summary = $service->getDepartmentSummary($record, $this->selectedSemester ? (int) $this->selectedSemester : null);

                        return $summary['total_submissions'];
                    })
                    ->badge()
                    ->color('info')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('avg_score')
                    ->label('Avg Score')
                    ->getStateUsing(function (Department $record) use ($service) {
                        $summary = $service->getDepartmentSummary($record, $this->selectedSemester ? (int) $this->selectedSemester : null);

                        return $summary['avg_score'];
                    })
                    ->numeric(2)
                    ->placeholder('—')
                    ->badge()
                    ->color(fn($state) => match (true) {
                        $state === null => 'gray',
                        $state >= 4.5 => 'success',
                        $state >= 3.5 => 'info',
                        $state >= 2.5 => 'warning',
                        default => 'danger',
                    })
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('rating')
                    ->label('Rating')
                    ->getStateUsing(function (Department $record) use ($service) {
                        $summary = $service->getDepartmentSummary($record, $this->selectedSemester ? (int) $this->selectedSemester : null);

                        return $summary['rating_label'];
                    })
                    ->badge()
                    ->color(fn($state) => match ($state) {
                        'Outstanding' => 'success',
                        'Very Satisfactory' => 'info',
                        'Satisfactory' => 'warning',
                        'Fair' => 'warning',
                        'Poor' => 'danger',
                        default => 'gray',
                    })
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('completion')
                    ->label('Completion')
                    ->getStateUsing(function (Department $record) use ($service) {
                        $summary = $service->getDepartmentSummary($record, $this->selectedSemester ? (int) $this->selectedSemester : null);

                        return $summary['completion_rate'] . '%';
                    })
                    ->alignCenter(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('export_csv')
                    ->label('Export CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(function () use ($service) {
                        $tenant = Filament::getTenant();
                        $query = Department::where('is_active', true)->orderBy('name');
                        if ($tenant) {
                            $query->where('campus_id', $tenant->id);
                        }

                        return response()->streamDownload(function () use ($service) {
                            $tenant = Filament::getTenant();
                            $query = Department::where('is_active', true)->orderBy('name');
                            if ($tenant) {
                                $query->where('campus_id', $tenant->id);
                            }
                            $departments = $query->get();
                            $handle = fopen('php://output', 'w');
                            fputcsv($handle, ['Department', 'Evaluatees', 'Submissions', 'Avg Score', 'Rating', 'Completion %']);

                            foreach ($departments as $dept) {
                                $summary = $service->getDepartmentSummary($dept, $this->selectedSemester ? (int) $this->selectedSemester : null);
                                fputcsv($handle, [
                                    $dept->name,
                                    $summary['total_evaluatees'],
                                    $summary['total_submissions'],
                                    $summary['avg_score'] ?? 'N/A',
                                    $summary['rating_label'],
                                    $summary['completion_rate'] . '%',
                                ]);
                            }

                            fclose($handle);
                        }, 'department-report-' . now()->format('Y-m-d') . '.csv');
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

        $coQuery = CourseOffering::where('is_active', true)->whereNotNull('form_id');
        if ($this->selectedSemester) {
            $coQuery->where('semester_id', (int) $this->selectedSemester);
        }
        if ($tenant) {
            $coQuery->whereHas('semester.schoolYear', fn($q) => $q->where('campus_id', $tenant->id));
        }
        $coIds = $coQuery->pluck('id');

        $distribution = $service->getScoreDistribution($coIds);

        $categories = ['Outstanding', 'Very Satisfactory', 'Satisfactory', 'Fair', 'Poor'];
        $colors = ['#10b981', '#3b82f6', '#f59e0b', '#f97316', '#ef4444'];

        return [
            'labels' => $categories,
            'data' => array_map(fn($c) => $distribution[$c] ?? 0, $categories),
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
            $semQuery->whereHas('schoolYear', fn($q) => $q->where('campus_id', $tenant->id));
        }
        $semesters = $semQuery->limit(8)->get();

        if ($semesters->isEmpty()) {
            return ['labels' => [], 'data' => []];
        }

        $labels = [];
        $data = [];

        foreach ($semesters as $sem) {
            $labels[] = ($sem->schoolYear?->name ?? '') . ' ' . $sem->name;

            $coQuery = CourseOffering::where('is_active', true)
                ->whereNotNull('form_id')
                ->where('semester_id', $sem->id);

            if ($tenant) {
                $coQuery->whereHas('semester.schoolYear', fn($q) => $q->where('campus_id', $tenant->id));
            }

            $coIds = $coQuery->pluck('id');
            if ($coIds->isEmpty()) {
                $data[] = null;
                continue;
            }

            $submissions = FormSubmission::whereIn('course_offering_id', $coIds)->with('form.fields')->get();
            $scores = $submissions->map(fn($s) => $service->calculateSubmissionScore($s))->filter();
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
