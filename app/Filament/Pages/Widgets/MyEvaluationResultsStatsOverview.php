<?php

namespace App\Filament\Pages\Widgets;

use App\Models\CourseOffering;
use App\Models\FormSubmission;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class MyEvaluationResultsStatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        $user = Auth::user();

        $courseCount = CourseOffering::where('user_id', $user->id)
            ->whereHas('formSubmissions')
            ->count();

        $totalSubmissions = FormSubmission::whereHas(
            'courseOffering',
            fn($q) => $q->where('user_id', $user->id),
        )->count();

        return [
            Stat::make('Courses Evaluated', number_format($courseCount))
                ->description('Courses with evaluation data')
                ->descriptionIcon('heroicon-m-academic-cap')
                ->color('info'),

            Stat::make('Total Evaluations Received', number_format($totalSubmissions))
                ->description('Evaluation responses submitted')
                ->descriptionIcon('heroicon-m-clipboard-document-check')
                ->color('warning'),
        ];
    }
}
