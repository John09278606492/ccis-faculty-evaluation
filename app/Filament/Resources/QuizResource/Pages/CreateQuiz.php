<?php

namespace App\Filament\Resources\QuizResource\Pages;

use App\Filament\Pages\QuizBuilder;
use App\Filament\Resources\QuizResource;
use Filament\Resources\Pages\CreateRecord;

class CreateQuiz extends CreateRecord
{
    protected static string $resource = QuizResource::class;

    protected function getRedirectUrl(): string
    {
        return QuizBuilder::getUrl(['record' => $this->record->id]);
    }
}
