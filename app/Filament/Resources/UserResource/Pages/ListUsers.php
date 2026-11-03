<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Imports\InstructorImporter;
use App\Filament\Resources\UserResource;
use App\Notifications\BellNotification;
use App\Services\BackgroundTasks;
use Filament\Actions;
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
            Actions\CreateAction::make()->label('Add Staff')->color('gray'),
            ImportAction::make('import_instructors')
                ->importer(InstructorImporter::class)
                ->visible(fn () => auth()->user()?->can('create_user'))
                ->label('Import Instructors')
                ->modalHeading('Import Instructors')
                ->modalSubmitActionLabel('Import Instructors')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->options(['campus_id' => $campusId])
                ->after(function () {
                    BellNotification::make()->success()->title('Instructor import queued')->body('Background processing is starting. You can continue working; completion and row errors will appear in this bell.')->sendToDatabase(auth()->user());
                }),
            Actions\Action::make('staffTemplate')->label('CSV Template')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->visible(fn () => auth()->user()?->can('create_user'))
                ->action(fn () => response()->streamDownload(function () {
                    $out = fopen('php://output', 'w');
                    fputcsv($out, ['first_name', 'middle_name', 'last_name', 'email', 'employee_id', 'position', 'phone']);
                    fputcsv($out, ['Maria', '', 'Dela Cruz', 'replace.with.actual.email@school.edu', 'EMP-001', 'Instructor', '']);
                    fclose($out);
                }, 'instructors-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8'])),
            Actions\Action::make('exportStaff')->label('Export Staff CSV')->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => app(BackgroundTasks::class)->enqueue(auth()->user(), filament()->getTenant(), 'staff-export', 'Export filtered staff accounts',
                    ['ids' => $this->getFilteredTableQuery()->pluck('users.id')->all()])),
        ];
    }
}
