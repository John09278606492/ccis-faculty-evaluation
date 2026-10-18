<?php

namespace App\Filament\Resources\QuizResource\Pages;

use App\Filament\Concerns\ReceivesClassroomUpdates;
use App\Filament\Resources\QuizResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListQuizzes extends ListRecords
{
    use ReceivesClassroomUpdates;

    protected static string $resource = QuizResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
