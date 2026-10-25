<?php

namespace App\Filament\Resources\CourseOfferingResource\Pages;

use App\Filament\Resources\CourseOfferingResource;
use App\Models\Course;
use Filament\Actions;
use App\Filament\Pages\DraftEditRecord as EditRecord;

class EditCourseOffering extends EditRecord
{
    protected static string $resource = CourseOfferingResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Assignment details';
    }

    public function getSubheading(): ?string
    {
        return 'Choose a form → confirm evaluation type → assign evaluators → review results. Student evaluations use ID entry; supervisors use their staff login.';
    }

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
            Actions\Action::make('studentAccess')->label('Evaluators')->icon('heroicon-o-user-group')
                ->visible(fn () => auth()->user()->can('manageEvaluators', $this->getRecord()))
                ->action(fn () => $this->activeRelationManager = '0'),
            Actions\DeleteAction::make(),
        ];
    }
}
