<?php

namespace App\Filament\Widgets;

use App\Models\User;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class UserRegistrationChart extends ChartWidget
{
    use HasWidgetShield;

    protected static ?string $heading = 'User Registrations';

    protected static ?string $description = 'New registrations over the last 12 months';

    protected static ?int $sort = 7;

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
        $months = 12;
        $rangeStart = Carbon::now()->subMonths($months - 1)->startOfMonth();

        // One grouped query instead of 3 count() queries per month (36 total) —
        // this chart renders on every Dashboard visit, so the saving compounds.
        // The month expression differs per driver: MySQL has DATE_FORMAT and
        // SQLite has strftime, so the same query works on either.
        $month = match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', campus_user.created_at)",
            default => "DATE_FORMAT(campus_user.created_at, '%Y-%m')",
        };

        if ($tenant) {
            $rows = DB::table('campus_user')
                ->join('users', 'users.id', '=', 'campus_user.user_id')
                ->where('campus_user.campus_id', $tenant->id)
                ->where('campus_user.created_at', '>=', $rangeStart)
                ->selectRaw("{$month} as ym,
                    SUM(CASE WHEN users.is_student = 1 THEN 1 ELSE 0 END) as students,
                    SUM(CASE WHEN users.is_instructor = 1 THEN 1 ELSE 0 END) as instructors,
                    SUM(CASE WHEN users.is_student = 0 AND users.is_instructor = 0 THEN 1 ELSE 0 END) as others")
                ->groupBy('ym')
                ->get()
                ->keyBy('ym');
        } else {
            $userMonth = match (DB::connection()->getDriverName()) {
                'sqlite' => "strftime('%Y-%m', created_at)",
                default => "DATE_FORMAT(created_at, '%Y-%m')",
            };
            $rows = User::query()
                ->where('created_at', '>=', $rangeStart)
                ->selectRaw("{$userMonth} as ym,
                    SUM(CASE WHEN is_student = 1 THEN 1 ELSE 0 END) as students,
                    SUM(CASE WHEN is_instructor = 1 THEN 1 ELSE 0 END) as instructors,
                    SUM(CASE WHEN is_student = 0 AND is_instructor = 0 THEN 1 ELSE 0 END) as others")
                ->groupBy('ym')
                ->get()
                ->keyBy('ym');
        }

        $studentsData = [];
        $instructorsData = [];
        $othersData = [];
        $labels = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $date = Carbon::now()->subMonths($i);
            $labels[] = $date->format('M Y');

            $row = $rows->get($date->format('Y-m'));
            $studentsData[] = (int) ($row->students ?? 0);
            $instructorsData[] = (int) ($row->instructors ?? 0);
            $othersData[] = (int) ($row->others ?? 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Students',
                    'data' => $studentsData,
                    'backgroundColor' => 'rgba(59, 130, 246, 0.5)',
                    'borderColor' => '#3b82f6',
                ],
                [
                    'label' => 'Instructors',
                    'data' => $instructorsData,
                    'backgroundColor' => 'rgba(139, 92, 246, 0.5)',
                    'borderColor' => '#8b5cf6',
                ],
                [
                    'label' => 'Other',
                    'data' => $othersData,
                    'backgroundColor' => 'rgba(107, 114, 128, 0.5)',
                    'borderColor' => '#6b7280',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
