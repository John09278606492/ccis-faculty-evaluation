<?php

namespace App\Filament\Resources\AcknowledgementFormResource\Pages;

use App\Filament\Resources\AcknowledgementFormResource;
use App\Services\AcknowledgementForms;
use Filament\Resources\Pages\CreateRecord;

class CreateAcknowledgementForm extends CreateRecord
{
    protected static string $resource = AcknowledgementFormResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        abort_unless(array_key_exists((int) $data['course_offering_id'], AcknowledgementFormResource::classOptions()), 403);

        return [...$data, 'created_by' => auth()->id()];
    }

    protected function afterCreate(): void
    {
        $forms = app(AcknowledgementForms::class);
        $forms->syncRoster($this->getRecord());
        $forms->notifyWaiting($this->getRecord(), 'New form to sign');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord(), 'activeRelationManager' => 0]);
    }
}
