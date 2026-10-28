<?php

namespace App\Filament\Resources\DepartmentResource\Pages;

use App\Filament\Pages\DraftEditRecord as EditRecord;
use App\Filament\Resources\DepartmentResource;
use Filament\Actions;

class EditDepartment extends EditRecord
{
    use \App\Filament\Concerns\QueuesDepartmentSupervisors;

    protected static string $resource = DepartmentResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->prepareDepartmentSupervisorBulk($data);
    }

    protected function afterSave(): void
    {
        $this->queueDepartmentSupervisorBulk();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
