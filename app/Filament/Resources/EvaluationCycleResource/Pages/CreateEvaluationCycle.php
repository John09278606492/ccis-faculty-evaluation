<?php

namespace App\Filament\Resources\EvaluationCycleResource\Pages;

use App\Filament\Pages\DraftCreateRecord as CreateRecord;
use App\Filament\Resources\EvaluationCycleResource;
use App\Services\EvaluationCycles;
use Illuminate\Database\Eloquent\Model;

class CreateEvaluationCycle extends CreateRecord
{
    protected static string $resource = EvaluationCycleResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(EvaluationCycles::class)->save(auth()->user(), filament()->getTenant(), $data);
    }

    protected function getRedirectUrl(): string
    {
        return EvaluationCycleResource::getUrl('edit', ['record' => $this->record]);
    }
}
