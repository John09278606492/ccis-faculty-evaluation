<?php

namespace App\Filament\Pages\Widgets;

use App\Services\AcademicPeriod;
use App\Services\StudentEvaluationProgress;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class MyEvaluationsStatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        $user = Auth::user();
        $enrolledCount = $user->courseOfferings()->count();
        $campus = filament()->getTenant();
        $progress = $campus ? app(StudentEvaluationProgress::class)->summary($user, $campus, AcademicPeriod::current($campus)) : ['completed' => 0, 'open_pending' => 0];
        $completedCount = $progress['completed'];
        $pendingCount = $progress['open_pending'];

        return [
            Stat::make('Enrolled Courses', number_format($enrolledCount))
                ->description('Courses you are enrolled in')
                ->descriptionIcon('heroicon-m-academic-cap')
                ->color('info'),

            Stat::make('Pending Evaluations', number_format($pendingCount))
                ->description('Awaiting your evaluation')
                ->descriptionIcon('heroicon-m-clock')
                ->color($pendingCount > 0 ? 'warning' : 'success'),

            Stat::make('Completed', number_format($completedCount))
                ->description('Evaluations submitted')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),
        ];
    }
}
