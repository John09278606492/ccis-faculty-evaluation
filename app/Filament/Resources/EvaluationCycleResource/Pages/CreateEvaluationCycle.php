<?php

namespace App\Filament\Resources\EvaluationCycleResource\Pages;

use App\Filament\Pages\DraftCreateRecord as CreateRecord;
use App\Filament\Resources\EvaluationCycleResource;
use App\Services\EvaluationCycles;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;

class CreateEvaluationCycle extends CreateRecord
{
    protected static string $resource = EvaluationCycleResource::class;

    protected static bool $canCreateAnother = false;

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Save Draft');
    }

    public function getSubheading(): ?string
    {
        return 'Save a draft, then review and open the evaluation.';
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(EvaluationCycles::class)->save(auth()->user(), filament()->getTenant(), $data);
    }

    protected function getRedirectUrl(): string
    {
        return EvaluationCycleResource::getUrl('edit', ['record' => $this->record]);
    }
}
