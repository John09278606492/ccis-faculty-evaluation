<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Imports\InstructorImporter;
use App\Filament\Resources\UserResource;
use App\Notifications\BellNotification;
use App\Services\BackgroundTasks;
use Filament\Actions;
use Filament\Actions\ActionGroup;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\On;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    /**
     * Listen for import completion and refresh table
     */
    #[On('importCompleted')]
    public function refreshTable(): void
    {
        $this->resetTable();
    }

    protected function getHeaderActions(): array
    {
        $campusId = filament()->getTenant()?->id;

        return [
            Actions\CreateAction::make(),
            ActionGroup::make([
                ImportAction::make('import_instructors')
                    ->importer(InstructorImporter::class)
                    ->visible(fn () => auth()->user()?->can('create_user'))
                    ->label('Import Instructors')
                    ->icon('heroicon-o-briefcase')
                    ->color('warning')
                    ->options(['campus_id' => $campusId])
                    ->after(function () {
                        BellNotification::make()->success()->title('Instructor import queued')->body('Background processing is starting. You can continue working; completion and row errors will appear in this bell.')->sendToDatabase(auth()->user());
                    }),
            ])
                ->label('Import Staff')
                ->icon('heroicon-o-arrow-up-tray')
                ->button(),
            Actions\Action::make('exportStaff')->label('Export Staff CSV')->icon('heroicon-o-arrow-down-tray')
                ->action(fn () => app(BackgroundTasks::class)->enqueue(auth()->user(), filament()->getTenant(), 'staff-export', 'Export filtered staff accounts',
                    ['ids' => $this->getFilteredTableQuery()->pluck('users.id')->all()])),
        ];
    }
}
