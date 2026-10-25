<?php

namespace App\Filament\Resources\AcademicStudentResource\Pages;

use App\Filament\Resources\AcademicStudentResource;
use App\Services\AcademicPeriod;
use App\Services\AcademicStudents;
use App\Services\StudentDeletion;
use Filament\Actions\DeleteAction;
use App\Filament\Pages\DraftEditRecord as EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAcademicStudent extends EditRecord
{
    protected static string $resource = AcademicStudentResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->modalDescription(StudentDeletion::NOTICE)
            ->using(fn (Model $record) => app(StudentDeletion::class)->delete(auth()->user(), filament()->getTenant(), [$record->id]) > 0)];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $semester = AcademicPeriod::current(filament()->getTenant());

        return [...$data, 'semester_id' => $semester, 'sections' => $semester ? app(AcademicStudents::class)->sections($this->record, $semester) : []];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(AcademicStudents::class)->save(auth()->user(), filament()->getTenant(), $data, $record);
    }
}
