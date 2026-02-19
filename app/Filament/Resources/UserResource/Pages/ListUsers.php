<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Imports\StudentImporter;
use App\Filament\Imports\InstructorImporter;
use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Actions\ActionGroup;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\On;
use pxlrbt\FilamentExcel\Columns\Column;
use pxlrbt\FilamentExcel\Exports\ExcelExport;
use pxlrbt\FilamentExcel\Actions\Pages\ExportAction;

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
                ImportAction::make('import_students')
                    ->importer(StudentImporter::class)
                    ->label('Import Students')
                    ->icon('heroicon-o-academic-cap')
                    ->color('info')
                    ->options(['campus_id' => $campusId])
                    ->after(fn() => $this->dispatch('importCompleted')),
                ImportAction::make('import_instructors')
                    ->importer(InstructorImporter::class)
                    ->label('Import Instructors')
                    ->icon('heroicon-o-briefcase')
                    ->color('warning')
                    ->options(['campus_id' => $campusId])
                    ->after(fn() => $this->dispatch('importCompleted')),
            ])
                ->label('Import Users')
                ->icon('heroicon-o-arrow-up-tray')
                ->button(),
            ExportAction::make()
                ->exports([
                    ExcelExport::make()
                        ->fromTable()
                        ->withFilename(fn($resource) => $resource::getModelLabel() . '-' . date('Y-m-d'))
                        ->withWriterType(\Maatwebsite\Excel\Excel::XLSX)
                        ->withColumns([
                            Column::make('updated_at'),
                        ])
                ]),
        ];
    }
}
