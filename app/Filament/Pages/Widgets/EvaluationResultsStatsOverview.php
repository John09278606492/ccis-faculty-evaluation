<?php

namespace App\Filament\Pages\Widgets;

use App\Models\FormSubmission;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class EvaluationResultsStatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        $campusId = filament()->getTenant()?->id;

        $totalEvaluatees = User::whereHas('courseOfferingsAsInstructor', function ($q) use ($campusId) {
            $q->when($campusId, fn($query) => $query->whereHas('semester.schoolYear', fn($sy) => $sy->where('campus_id', $campusId)));
        })->count();

        $evaluatedEvaluatees = User::whereHas('courseOfferingsAsInstructor', function ($q) use ($campusId) {
            $q->when($campusId, fn($query) => $query->whereHas('semester.schoolYear', fn($sy) => $sy->where('campus_id', $campusId)));
        })->whereHas('courseOfferingsAsInstructor.formSubmissions')->count();

        $totalSubmissions = FormSubmission::whereHas('courseOffering', function ($q) use ($campusId) {
            $q->whereHas('semester.schoolYear', fn($sy) => $sy->when($campusId, fn($s) => $s->where('campus_id', $campusId)));
        })->count();

        return [
            Stat::make('Total Evaluatees', number_format($totalEvaluatees))
                ->description('Assigned for evaluation')
                ->descriptionIcon('heroicon-m-users')
                ->color('info'),

            Stat::make('Evaluated', number_format($evaluatedEvaluatees))
                ->description('Received at least 1 submission')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success'),

            Stat::make('Total Evaluations', number_format($totalSubmissions))
                ->description('Evaluation responses submitted')
                ->descriptionIcon('heroicon-m-clipboard-document-check')
                ->color('warning'),
        ];
    }
}
