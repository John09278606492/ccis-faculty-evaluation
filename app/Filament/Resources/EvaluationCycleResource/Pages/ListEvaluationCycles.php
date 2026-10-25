<?php

namespace App\Filament\Resources\EvaluationCycleResource\Pages;

use App\Filament\Concerns\ShowsEvaluationWorkflow;
use App\Filament\Resources\EvaluationCycleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEvaluationCycles extends ListRecords
{
    use ShowsEvaluationWorkflow;

    protected static string $view = 'filament.resources.workflow-list';

    protected static string $resource = EvaluationCycleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Start New Evaluation')];
    }

    public function getSubheading(): ?string
    {
        return 'Step 3: choose the forms, schedule and teaching classes. Save & Review checks the setup before students and supervisors can evaluate.';
    }
}
