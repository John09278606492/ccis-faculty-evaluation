<?php

namespace App\Filament\Resources\AcademicStudentResource\Pages;

use App\Filament\Resources\AcademicStudentResource;
use App\Services\AcademicStudents;
use App\Filament\Pages\DraftCreateRecord as CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAcademicStudent extends CreateRecord
{
    protected static string $resource = AcademicStudentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(AcademicStudents::class)->save(auth()->user(), filament()->getTenant(), $data);
    }
}
