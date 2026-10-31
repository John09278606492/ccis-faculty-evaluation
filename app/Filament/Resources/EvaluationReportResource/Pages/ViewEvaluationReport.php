<?php

namespace App\Filament\Resources\EvaluationReportResource\Pages;

use App\Filament\Pages\EvaluationResults;
use App\Filament\Resources\EvaluationReportResource;
use Filament\Resources\Pages\ViewRecord;

class ViewEvaluationReport extends ViewRecord
{
    protected static string $resource = EvaluationReportResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->redirect(EvaluationResults::getUrl(['tableSearch' => $this->record->name]));
    }
}
