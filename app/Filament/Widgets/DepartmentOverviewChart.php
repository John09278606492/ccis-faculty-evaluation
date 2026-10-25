<?php

namespace App\Filament\Widgets;

use App\Models\Department;
use App\Services\AcademicPeriod;
use App\Services\AssignedDataAccess;
use App\Services\ReportService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

class DepartmentOverviewChart extends ChartWidget
{
    use HasWidgetShield;

    protected static ?string $heading = 'Department Overview';

    protected static ?string $description = 'Assigned courses and faculty in the selected semester';

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 1;

    protected static ?string $maxHeight = '250px';

    public static function canView(): bool
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            return true;
        }

        $permissionName = static::getPermissionName();

        return $user->can($permissionName);
    }

    protected function getData(): array
    {
        $tenant = Filament::getTenant();

        $query = Department::where('is_active', true)->when($tenant, fn ($q) => $q->where('campus_id', $tenant->id));

        if ($tenant) {
            app(AssignedDataAccess::class)->apply($query, auth()->user(), $tenant);
        }
        $departments = $query
            ->orderBy('name')
            ->limit(10)
            ->get();

        foreach ($departments as $department) {
            $context = ['campus_id' => $tenant?->id, 'department_id' => $department->id, 'semester_id' => $tenant ? AcademicPeriod::current($tenant) : null];
            $offerings = app(ReportService::class)->offerings($context)->get(['user_id', 'course_id']);
            $department->setAttribute('courses_count', $offerings->pluck('course_id')->filter()->unique()->count());
            $department->setAttribute('instructors_count', $offerings->pluck('user_id')->unique()->count());
        }

        return [
            'datasets' => [
                [
                    'label' => 'Courses',
                    'data' => $departments->pluck('courses_count')->toArray(),
                    'backgroundColor' => '#3b82f6',
                    'borderColor' => '#2563eb',
                ],
                [
                    'label' => 'Evaluatees',
                    'data' => $departments->pluck('instructors_count')->toArray(),
                    'backgroundColor' => '#8b5cf6',
                    'borderColor' => '#7c3aed',
                ],
            ],
            'labels' => $departments->pluck('name')->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
