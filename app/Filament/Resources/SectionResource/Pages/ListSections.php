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
        return 'Step 1: create a section, open Upload / Manage Students, and upload its list. Students are validated against the confirmed master list. Teaching Classes reuse these saved sections.';
    }

    protected static string $resource = SectionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
