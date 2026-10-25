<?php

namespace App\Filament\Resources\DepartmentResource\Pages;

use App\Filament\Pages\DraftEditRecord as EditRecord;
use App\Filament\Resources\DepartmentResource;
use Filament\Actions;

class EditDepartment extends EditRecord
{
    protected static string $resource = DepartmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
