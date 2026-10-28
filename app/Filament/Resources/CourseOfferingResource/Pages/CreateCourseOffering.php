<?php

namespace App\Filament\Resources\CourseOfferingResource\Pages;

use App\Filament\Pages\DraftCreateRecord as CreateRecord;
use App\Filament\Resources\CourseOfferingResource;

class CreateCourseOffering extends CreateRecord
{
    protected static string $resource = CourseOfferingResource::class;
}
