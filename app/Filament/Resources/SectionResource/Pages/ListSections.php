<?php

namespace App\Filament\Resources\SectionResource\Pages;

use App\Filament\Concerns\ShowsEvaluationWorkflow;
use App\Filament\Resources\SectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSections extends ListRecords
{
    use ShowsEvaluationWorkflow;

    protected static string $view = 'filament.resources.workflow-list';

    public function getSubheading(): ?string
    {
        return 'Create a section, then upload the students who belong to it.';
    }

    protected static string $resource = SectionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add Section')];
    }
}
