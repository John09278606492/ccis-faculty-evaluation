<?php

namespace App\Filament\Pages\Widgets;

use App\Models\FormSubmission;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class MyEvaluationsStatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        $user = Auth::user();
        $enrolledCount = $user->courseOfferings()->count();
        $withFormCount = $user->courseOfferings()->whereNotNull('form_id')->count();
        $completedCount = FormSubmission::where('user_id', $user->id)
            ->whereIn('course_offering_id', $user->courseOfferings()->pluck('course_offerings.id'))
            ->distinct('course_offering_id')
            ->count('course_offering_id');
        $pendingCount = max(0, $withFormCount - $completedCount);

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
