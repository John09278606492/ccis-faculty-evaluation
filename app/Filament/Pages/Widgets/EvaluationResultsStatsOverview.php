<?php

namespace App\Filament\Pages\Widgets;

use App\Models\User;
use App\Services\ReportService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class EvaluationResultsStatsOverview extends BaseWidget
{
    public array $reportContext = [];

    protected function getStats(): array
    {
        $context = ['campus_id' => filament()->getTenant()?->id, ...$this->reportContext];
        $service = app(ReportService::class);
        $faculty = User::whereIn('id', $service->offerings($context)->select('user_id'))->get();
        $summaries = $faculty->map(fn ($person) => $service->getEvaluateeSummary($person, null, null, $context));

        return [Stat::make('Faculty', $faculty->count())->description('In the selected reporting scope'),
            Stat::make('Complete Responses', $summaries->sum('complete_responses'))->description('Contribute to ratings'),
            Stat::make('Incomplete Responses', $summaries->sum('incomplete_responses'))->description('Excluded from ratings')];
    }
}
