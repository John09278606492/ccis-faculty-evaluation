<?php

namespace App\Filament\Resources\FormResource\Pages;

use App\Filament\Concerns\ShowsEvaluationWorkflow;
use App\Filament\Resources\FormResource;
use App\Models\Form;
use App\Services\EvaluationTemplates;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;

class ListForms extends ListRecords
{
    use ShowsEvaluationWorkflow;

    protected static string $view = 'filament.resources.workflow-list';

    public function getTitle(): string
    {
        return 'Evaluation Forms';
    }

    public function getSubheading(): ?string
    {
        return 'Annex A is for students; Annex B is for supervisors. Use an Annex template or duplicate an existing form to edit it. Select the completed forms in Evaluation Setup.';
    }

    protected static string $resource = FormResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('useTemplate')->label('Use Annex Template')->icon('heroicon-o-document-duplicate')
                ->visible(fn () => auth()->user()->can('create', Form::class))
                ->form([Select::make('annex')->label('Official instrument')->options(EvaluationTemplates::NAMES)->required()])
                ->action(function (array $data) {
                    $this->authorize('create', Form::class);
                    $form = app(EvaluationTemplates::class)->create($data['annex'], filament()->getTenant());
                    $this->redirect(FormResource::getUrl('edit', ['record' => $form]));
                }),
            CreateAction::make(),
        ];
    }
}
