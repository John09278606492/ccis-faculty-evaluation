<?php

namespace App\Filament\Resources\AdvisoryClassResource\Pages;

use App\Filament\Resources\AdvisoryClassResource;
use App\Services\Advising;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageAdvisoryClasses extends ManageRecords
{
    protected static string $resource = AdvisoryClassResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Add advisory section')->mutateFormDataUsing(function (array $data): array {
            abort_unless(array_key_exists((int) $data['academic_section_id'], app(Advising::class)->freeSections(filament()->getTenant()?->id)), 403);

            return [...$data, 'adviser_id' => auth()->id(), 'created_by' => auth()->id()];
        })];
    }
}
