<?php

namespace App\Filament\Resources\SectionResource\Pages;

use App\Filament\Pages\DraftEditRecord as EditRecord;
use App\Filament\Resources\SectionResource;
use App\Services\AcademicSections;
use Filament\Actions\DeleteAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditSection extends EditRecord
{
    protected static string $resource = SectionResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Section details';
    }

    protected function getHeaderActions(): array
    {
        return [SectionResource::deleteAction(DeleteAction::class)];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(AcademicSections::class)->save(auth()->user(), filament()->getTenant(), $data, $record);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $key) => ['data.'.$key => $messages])->all());
        }
    }
}
