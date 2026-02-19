<?php

namespace App\Filament\Resources\CourseOfferingResource\Pages;

use App\Filament\Resources\CourseOfferingResource;
use Filament\Resources\Pages\ListRecords;

class ListCourseOfferings extends ListRecords
{
    protected static string $resource = CourseOfferingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
