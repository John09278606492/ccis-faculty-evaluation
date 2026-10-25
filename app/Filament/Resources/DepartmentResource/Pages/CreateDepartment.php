<?php

namespace App\Filament\Resources\DepartmentResource\Pages;

use App\Filament\Pages\DraftCreateRecord as CreateRecord;
use App\Filament\Resources\DepartmentResource;

class CreateDepartment extends CreateRecord
{
    protected static string $resource = DepartmentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['campus_id'] = filament()->getTenant()->id;

        return $data;
    }
}
