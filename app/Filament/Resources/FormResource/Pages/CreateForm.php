<?php

namespace App\Filament\Resources\FormResource\Pages;

use App\Filament\Pages\DraftCreateRecord as CreateRecord;
use App\Filament\Resources\FormResource;

class CreateForm extends CreateRecord
{
    protected static string $resource = FormResource::class;

    protected function afterCreate(): void
    {
        // For non-super_admin, always attach the current tenant campus
        if (! auth()->user()?->hasRole('super_admin')) {
            $campusId = filament()->getTenant()?->id;
            if ($campusId) {
                $this->record->campuses()->syncWithoutDetaching([$campusId]);
            }
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
