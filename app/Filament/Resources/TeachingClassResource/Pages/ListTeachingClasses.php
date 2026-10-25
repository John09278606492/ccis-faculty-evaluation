<?php

namespace App\Filament\Resources\TeachingClassResource\Pages;

use App\Filament\Concerns\ShowsEvaluationWorkflow;
use App\Filament\Resources\TeachingClassResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTeachingClasses extends ListRecords
{
    use ShowsEvaluationWorkflow;

    protected static string $view = 'filament.resources.workflow-list';

    protected static string $resource = TeachingClassResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add Teaching Class')];
    }

    public function getSubheading(): ?string
    {
        return '1. Upload student lists in Sections. 2. Create the faculty’s teaching class. 3. Assign the sections they teach.';
    }
}
