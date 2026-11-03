<?php

namespace App\Filament\Resources\EvaluationReportResource\Pages;

use App\Filament\Pages\EvaluationResults;
use App\Filament\Resources\EvaluationReportResource;
use Filament\Resources\Pages\ListRecords;

/** Old bookmarked report links lead to the single faculty results workspace. */
class ListEvaluationReports extends ListRecords
{
    protected static string $resource = EvaluationReportResource::class;

    public function mount(): void
    {
        parent::mount();
        $this->redirect(EvaluationResults::getUrl());
    }
}
