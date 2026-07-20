<?php

namespace App\Filament\Widgets;

use App\Models\CourseOffering;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

class EvaluationCompletionChart extends ChartWidget
{
    use HasWidgetShield;

    protected static ?string $heading = 'Evaluation Completion Rate';

    protected static ?string $description = 'Completion status across active evaluation assignments';

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

        $query = CourseOffering::where('is_active', true)
            ->whereNotNull('form_id');

        if ($tenant) {
            $query->whereHas('semester.schoolYear', fn ($q) => $q->where('campus_id', $tenant->id));
        }

        // Instructors see only their own course offerings
        if (! $user->isSuperAdmin() && $user->isInstructor() && ! $user->can('view_any_course::offering')) {
            $query->where('user_id', $user->id);
        }

        $courseOfferings = $query->withCount(['students', 'formSubmissions'])->get();

        $fullyCompleted = 0;
        $partiallyCompleted = 0;
        $notStarted = 0;

        foreach ($courseOfferings as $offering) {
            if ($offering->students_count === 0) {
                $notStarted++;

                continue;
            }

            $completionRate = $offering->form_submissions_count / $offering->students_count;

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
