<?php

namespace App\Filament\Pages\Widgets;

use App\Services\EvaluationResultsRelease;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class MyEvaluationResultsStatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        $user = Auth::user();

        $offerings = EvaluationResultsRelease::ownOfferings($user);
        $courseCount = $offerings->count();
        $totalSubmissions = $offerings->sum('form_submissions_count');

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
