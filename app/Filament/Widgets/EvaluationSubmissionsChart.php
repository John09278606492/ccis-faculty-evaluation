<?php

namespace App\Filament\Widgets;

use App\Models\FormSubmission;
use App\Services\AcademicPeriod;
use App\Services\ReportService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class EvaluationSubmissionsChart extends ChartWidget
{
    use HasWidgetShield;

    protected static ?string $heading = 'Evaluation Submissions';

    protected static ?string $description = 'Current-instrument responses over 30 days in the selected semester';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    protected static ?string $maxHeight = '250px';

    public static function canView(): bool
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->can(static::getPermissionName());
    }

    protected function getData(): array
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();
        $days = 30;
        $rangeStart = Carbon::now()->subDays($days - 1)->startOfDay();

        // One grouped query instead of 30 count() queries (one per day) — this
        // chart renders on every Dashboard visit, so the saving compounds.
        $ids = app(ReportService::class)->offerings(['campus_id' => $tenant?->id, 'semester_id' => $tenant ? AcademicPeriod::current($tenant) : null])->select('course_offerings.id');
        $query = FormSubmission::query()->whereIn('course_offering_id', $ids)
            ->whereHas('courseOffering', fn ($q) => $q->whereColumn('course_offerings.form_id', 'form_submissions.form_id'))
            ->where('submitted_at', '>=', $rangeStart)
            ->selectRaw('DATE(submitted_at) as day, COUNT(*) as total')
            ->groupBy('day');

        if ($tenant) {
            $query->whereHas('courseOffering.semester.schoolYear', fn ($q) => $q->where('campus_id', $tenant->id));
        }

        // Instructors only see their own evaluations
        if (! $user->isSuperAdmin() && $user->isInstructor() && ! $user->can('view_any_form')) {
            $query->whereHas('courseOffering', fn ($q) => $q->where('user_id', $user->id));
        }

        $countsByDay = $query->pluck('total', 'day');

        $data = [];
        $labels = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $labels[] = $date->format('M d');
            $data[] = (int) ($countsByDay[$date->toDateString()] ?? 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Submissions',
                    'data' => $data,
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                    'fill' => true,
                    'tension' => 0.4,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
