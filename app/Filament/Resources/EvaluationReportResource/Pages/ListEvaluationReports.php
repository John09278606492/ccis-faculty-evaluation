<?php

namespace App\Filament\Resources\EvaluationReportResource\Pages;

use App\Filament\Resources\EvaluationReportResource;
use App\Filament\Resources\EvaluationReportResource\Widgets\EvaluationReportStatsOverview;
use Filament\Resources\Pages\ListRecords;

class ListEvaluationReports extends ListRecords
{
    protected static string $resource = EvaluationReportResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            EvaluationReportStatsOverview::class,
        ];
    }

    public function getTitle(): string
    {
        return 'Evaluation Reports';
    }
}
