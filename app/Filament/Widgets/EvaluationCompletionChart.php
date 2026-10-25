<?php

namespace App\Filament\Widgets;

use App\Services\AcademicPeriod;
use App\Services\ReportService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

class EvaluationCompletionChart extends ChartWidget
{
    use HasWidgetShield;

    protected static ?string $heading = 'Evaluation Completion Rate';

    protected static ?string $description = 'Unique assigned students with current SET responses in the selected semester';

    protected static ?int $sort = 4;

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

        $query = app(ReportService::class)->offerings(['campus_id' => $tenant?->id,
            'semester_id' => $tenant ? AcademicPeriod::current($tenant) : null, 'evaluation_type' => 'SET']);

        // Instructors see only their own course offerings
        if (! $user->isSuperAdmin() && $user->isInstructor() && ! $user->can('view_any_course::offering')) {
            $query->where('user_id', $user->id);
        }

        $courseOfferings = $query->withCount(['students', 'formSubmissions'])->get();

        $fullyCompleted = 0;
        $partiallyCompleted = 0;
        $notStarted = 0;

        foreach ($courseOfferings as $offering) {
            $completion = app(ReportService::class)->assignmentCompletion($offering);
            if ($completion['expected'] === 0) {
                continue;
            }
            $completionRate = $completion['completed'] / $completion['expected'];

            if ($completionRate >= 1) {
                $fullyCompleted++;
            } elseif ($completionRate > 0) {
                $partiallyCompleted++;
            } else {
                $notStarted++;
            }
        }

        return [
            'datasets' => [
                [
                    'data' => [$fullyCompleted, $partiallyCompleted, $notStarted],
                    'backgroundColor' => ['#10b981', '#f59e0b', '#ef4444'],
                    'borderColor' => ['#059669', '#d97706', '#dc2626'],
                ],
            ],
            'labels' => ['Completed', 'In Progress', 'Not Started'],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
