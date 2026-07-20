<?php

namespace App\Filament\Widgets;

use App\Models\Department;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

class DepartmentOverviewChart extends ChartWidget
{
    use HasWidgetShield;

    protected static ?string $heading = 'Department Overview';

    protected static ?string $description = 'Courses & evaluatees per department';

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

        $query = $tenant
            ? $tenant->departments()->where('is_active', true)
            : Department::where('is_active', true);

        $departments = $query
            ->withCount(['courses', 'instructors'])
            ->orderBy('name')
            ->limit(10)
            ->get();

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
