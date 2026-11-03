<?php

namespace App\Filament\Resources\CourseResource\Pages;

use App\Filament\Pages\DraftEditRecord as EditRecord;
use App\Filament\Resources\CourseResource;
use Filament\Actions;

class EditCourse extends EditRecord
{
    protected static string $resource = CourseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
