<?php

namespace App\Filament\Resources\CourseOfferingResource\Pages;

use App\Filament\Resources\CourseOfferingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCourseOfferings extends ListRecords
{
    protected static string $resource = CourseOfferingResource::class;

    public function getSubheading(): ?string
    {
        return 'Set up faculty evaluations here. Use Evaluators to manage the class list. Students enter their ID to complete assigned evaluations.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
