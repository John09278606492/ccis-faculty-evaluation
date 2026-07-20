<?php

namespace App\Filament\Widgets;

use App\Models\FormSubmission;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class EvaluationSubmissionsChart extends ChartWidget
{
    use HasWidgetShield;

    protected static ?string $heading = 'Evaluation Submissions';

    protected static ?string $description = 'Submissions over the last 30 days';

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
        $query = FormSubmission::query()
            ->where('submitted_at', '>=', $rangeStart)
            ->selectRaw('DATE(submitted_at) as day, COUNT(*) as total')
            ->groupBy('day');

        if ($tenant) {
            $query->whereHas('form.campuses', fn ($q) => $q->where('campuses.id', $tenant->id));
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
