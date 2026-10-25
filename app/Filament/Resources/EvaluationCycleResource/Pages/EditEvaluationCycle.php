<?php

namespace App\Filament\Resources\EvaluationCycleResource\Pages;

use App\Filament\Pages\DraftEditRecord as EditRecord;
use App\Filament\Pages\EvaluationResults;
use App\Filament\Pages\StudentEvaluationProgress;
use App\Filament\Resources\EvaluationCycleResource;
use App\Filament\Resources\TeachingClassResource;
use App\Services\EvaluationCycles;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

class EditEvaluationCycle extends EditRecord
{
    protected static string $resource = EvaluationCycleResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, 'assignment_ids' => $this->record->assignments()->whereHas('course')->pluck('id')->all()];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(EvaluationCycles::class)->save(auth()->user(), filament()->getTenant(), $data, $record);
    }

    public function getSubheading(): ?string
    {
        return match ($this->record->status) {
            'draft' => 'Step 3: complete the setup, then use Save & Review. The evaluation opens only after you confirm the checklist and its opening date is reached.',
            'published' => 'This setup is published. Responses follow the selected schedule. Use Step 4 or Related pages to monitor and print.',
            default => 'This evaluation is '.$this->record->status.'. New responses are stopped; submitted evaluations and reports remain preserved.',
        };
    }

    protected function getFormActions(): array
    {
        return $this->record->status === 'draft' ? parent::getFormActions() : [];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('review')->label('Save & Review')->icon('heroicon-o-check-badge')->visible(fn () => $this->record->status === 'draft')
                ->mountUsing(fn () => $this->save(shouldRedirect: false, shouldSendSavedNotification: false))
                ->modalHeading('Review evaluation setup')->modalContent(fn () => view('filament.pages.partials.cycle-readiness', ['cycle' => $this->record, 'readiness' => app(EvaluationCycles::class)->readiness($this->record)]))
                ->modalSubmitActionLabel('Open evaluation')->action(function () {
                    app(EvaluationCycles::class)->publish(auth()->user(), $this->record);
                    $this->record->refresh();
                    Notification::make()->success()->title('Evaluation ready')->body('Students can respond during the selected schedule. Supervisor tasks have been prepared.')->send();
                }),
            ActionGroup::make([
                Action::make('monitor')->label('Monitor Students')->visible(fn () => StudentEvaluationProgress::canAccess())->url(fn () => StudentEvaluationProgress::getUrl(['tableFilters' => ['department' => ['value' => $this->record->department_id], 'semester' => ['value' => $this->record->semester_id]]])),
                Action::make('assignments')->label('Teaching Classes')->visible(fn () => TeachingClassResource::canViewAny())->url(fn () => TeachingClassResource::getUrl('index', ['tableFilters' => ['semester_id' => ['value' => $this->record->semester_id], 'department' => ['value' => $this->record->department_id]]])),
                Action::make('results')->label('Results & Reports')->visible(fn () => EvaluationResults::canAccess())->url(fn () => EvaluationResults::getUrl(['tableFilters' => ['semester' => ['value' => $this->record->semester_id], 'cycle' => ['value' => $this->record->id]]])),
            ])->label('Related pages')->icon('heroicon-o-ellipsis-horizontal')->button()->color('gray'),
            Action::make('close')->label('Close Evaluation')->visible(fn () => $this->record->status === 'published')->requiresConfirmation()->modalDescription('Stop accepting new evaluations. Existing responses remain available in reports.')
                ->action(function () {
                    app(EvaluationCycles::class)->close(auth()->user(), $this->record);
                    $this->record->refresh();
                }),
            Action::make('archive')->label('Archive Evaluation')->visible(fn () => $this->record->status === 'closed')->requiresConfirmation()->modalDescription('Archive this closed evaluation. All responses and reports are retained.')
                ->action(function () {
                    app(EvaluationCycles::class)->close(auth()->user(), $this->record, true);
                    $this->record->refresh();
                }),
        ];
    }
}
