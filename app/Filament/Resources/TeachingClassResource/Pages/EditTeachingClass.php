<?php

namespace App\Filament\Resources\TeachingClassResource\Pages;

use App\Filament\Pages\DraftEditRecord as EditRecord;
use App\Filament\Resources\TeachingClassResource;
use App\Services\TeachingClasses;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditTeachingClass extends EditRecord
{
    protected static string $resource = TeachingClassResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Class details';
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(TeachingClasses::class)->save(auth()->user(), filament()->getTenant(), $data, $record);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $key) => ['data.'.$key => $messages])->all());
        }
    }

    public function getSubheading(): ?string
    {
        return 'Match enrolled students or choose saved sections, then select supervisor evaluators.';
    }
}
