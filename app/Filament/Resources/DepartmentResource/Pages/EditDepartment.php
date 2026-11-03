<?php

namespace App\Filament\Resources\DepartmentResource\Pages;

use App\Filament\Actions\AssignDepartmentInstructors;
use App\Filament\Concerns\QueuesDepartmentSupervisors;
use App\Filament\Pages\DraftEditRecord as EditRecord;
use App\Filament\Resources\DepartmentResource;
use Filament\Actions;

class EditDepartment extends EditRecord
{
    use QueuesDepartmentSupervisors;

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
            AssignDepartmentInstructors::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
