<?php

namespace App\Filament\Resources\SectionResource\Pages;

use App\Filament\Pages\DraftCreateRecord as CreateRecord;
use App\Filament\Resources\SectionResource;
use App\Services\AcademicSections;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateSection extends CreateRecord
{
    protected static string $resource = SectionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(AcademicSections::class)->save(auth()->user(), filament()->getTenant(), $data);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $key) => ['data.'.$key => $messages])->all());
        }
    }

    protected function getRedirectUrl(): string
    {
        return SectionResource::getUrl('edit', ['record' => $this->record, 'activeRelationManager' => '0']);
    }
}
