<?php

namespace App\Filament\Resources\TeachingClassResource\Pages;

use App\Filament\Resources\TeachingClassResource;
use App\Services\TeachingClasses;
use App\Filament\Pages\DraftCreateRecord as CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateTeachingClass extends CreateRecord
{
    protected static string $resource = TeachingClassResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(TeachingClasses::class)->save(auth()->user(), filament()->getTenant(), $data);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $key) => ['data.'.$key => $messages])->all());
        }
    }

    protected function getRedirectUrl(): string
    {
        return TeachingClassResource::getUrl('edit', ['record' => $this->record, 'activeRelationManager' => '0']);
    }
}
