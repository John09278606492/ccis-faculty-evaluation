<?php

namespace App\Filament\Resources\QuizResource\Pages;

use App\Filament\Concerns\ReceivesClassroomUpdates;
use App\Filament\Resources\QuizResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;

class ListQuizzes extends ListRecords
{
    use ReceivesClassroomUpdates;

    protected static string $resource = QuizResource::class;

    public function getSubheading(): ?string
    {
        return 'Manage quizzes, exams and activities here. Open Students & sign-in to assign students, issue PINs and approve waiting browsers.';
    }

    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make('All assessments')];
        foreach (['quiz' => 'Quizzes', 'exam' => 'Exams', 'activity' => 'Activities'] as $type => $label) {
            $tabs[$type] = Tab::make($label)->modifyQueryUsing(fn ($query) => $query->where('type', $type));
        }

        return $tabs;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
