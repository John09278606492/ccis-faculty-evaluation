<?php

namespace App\Filament\Resources\CourseResource\Pages;

use App\Filament\Pages\DraftCreateRecord as CreateRecord;
use App\Filament\Resources\CourseResource;

class CreateCourse extends CreateRecord
{
    protected static string $resource = CourseResource::class;
}
