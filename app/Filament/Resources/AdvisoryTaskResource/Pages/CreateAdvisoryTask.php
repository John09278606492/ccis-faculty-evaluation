<?php

namespace App\Filament\Resources\AdvisoryTaskResource\Pages;

use App\Filament\Resources\AdvisoryTaskResource;
use App\Services\Advising;
use Filament\Resources\Pages\CreateRecord;

class CreateAdvisoryTask extends CreateRecord
{
    protected static string $resource = AdvisoryTaskResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        abort_unless(array_key_exists((int) $data['advisory_class_id'], AdvisoryTaskResource::classOptions()), 403);
        abort_unless(array_key_exists((int) $data['advising_semester_id'], AdvisoryTaskResource::semesterOptions()), 403);

        return [...$data, 'created_by' => auth()->id()];
    }

    protected function afterCreate(): void
    {
        app(Advising::class)->syncRoster($this->record);
        app(Advising::class)->notifyWaiting($this->record, 'New academic advising requirements');
    }
}
