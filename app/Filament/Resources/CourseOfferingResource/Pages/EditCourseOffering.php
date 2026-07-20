<?php

namespace App\Filament\Resources\CourseOfferingResource\Pages;

use App\Filament\Resources\CourseOfferingResource;
use App\Models\Course;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCourseOffering extends EditRecord
{
    protected static string $resource = CourseOfferingResource::class;

    /**
     * Populate the department_id helper field from the course relationship.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Load the course to get its department_id
        if (isset($data['course_id'])) {
            $course = Course::find($data['course_id']);
            if ($course) {
                $data['department_id'] = $course->department_id;
            }
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
