<?php

namespace App\Filament\Resources\TeachingClassResource\Pages;

use App\Filament\Pages\DraftEditRecord as EditRecord;
use App\Filament\Resources\TeachingClassResource;
use App\Services\TeachingClasses;
use Filament\Actions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditTeachingClass extends EditRecord
{
    protected static string $resource = TeachingClassResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Delete unused class')->databaseTransaction()
                ->visible(fn () => auth()->user()->can('delete', $this->record) && TeachingClassResource::deletable($this->record))
                ->modalDescription('Remove this unused teaching class and its student links. Student accounts and saved sections remain. This cannot be undone.')
                ->before(fn () => abort_unless(auth()->user()->can('delete', $this->record) && TeachingClassResource::deletable($this->record->fresh()), 403)),
            Actions\Action::make('deactivate')->label('Deactivate class')->color('warning')->requiresConfirmation()
                ->visible(fn () => $this->record->is_active && auth()->user()->can('update', $this->record))
                ->modalDescription('Stop using this teaching class. Keep its student links, submitted evaluations and assessment records for reference. You can reactivate it from Class details.')
                ->action(function () {
                    abort_unless(auth()->user()->can('update', $this->record), 403);
                    $this->record->update(['is_active' => false]);
                    \Filament\Notifications\Notification::make()->success()->title('Class deactivated')->send();
                }),
        ];
    }

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
