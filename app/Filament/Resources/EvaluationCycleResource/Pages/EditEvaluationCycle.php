<?php

namespace App\Filament\Resources\EvaluationCycleResource\Pages;

use App\Filament\Pages\DraftEditRecord as EditRecord;
use App\Filament\Pages\EvaluationResults;
use App\Filament\Pages\StudentEvaluationProgress;
use App\Filament\Resources\EvaluationCycleResource;
use App\Filament\Resources\TeachingClassResource;
use App\Services\DepartmentSupervisorAssignments;
use App\Services\EvaluationClassAdditions;
use App\Services\EvaluationCycles;
use App\Services\SupervisorAssignmentGaps;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\StaticAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

class EditEvaluationCycle extends EditRecord
{
    protected static string $resource = EvaluationCycleResource::class;

    #[Locked]
    public array $classAdditionPreview = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, 'assignment_ids' => $this->record->assignments()->whereHas('course')->pluck('id')->all(),
            'class_selection_mode' => $this->record->class_selection['mode'] ?? 'manual', 'faculty_ids' => $this->record->class_selection['faculty_ids'] ?? []];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(EvaluationCycles::class)->save(auth()->user(), filament()->getTenant(), $data, $record);
    }

    public function getSubheading(): ?string
    {
        return match ($this->record->status) {
            'draft' => 'Complete the details, then choose Review & Open.',
            'published' => $this->record->ends_at ? 'Accepts responses within the selected schedule.' : 'Accepts responses from the opening time until you select Close Evaluation.',
            default => 'This evaluation is '.$this->record->status.'. Previous responses are saved.',
        };
    }

    protected function getFormActions(): array
    {
        return $this->record->status === 'draft' ? parent::getFormActions() : [];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Save Draft')->color('gray');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('review')->label('Review & Open')->icon('heroicon-o-check-badge')->visible(fn () => $this->record->status === 'draft')
                ->stickyModalHeader()->stickyModalFooter()->modalWidth('5xl')
                ->mountUsing(fn () => $this->save(shouldRedirect: false, shouldSendSavedNotification: false))
                ->modalHeading('Review evaluation setup')->modalContent(fn () => view('filament.pages.partials.cycle-readiness', ['cycle' => $this->record, 'readiness' => app(EvaluationCycles::class)->readiness($this->record)]))
                ->extraModalFooterActions([
                    $this->supervisorGapAction('resolveSupervisorGapsInReview')->cancelParentActions(),
                    Action::make('departmentSupervisorsInReview')->label('Use Department Supervisor')->color('gray')
                        ->visible(fn () => $this->canUseDepartmentSupervisor())
                        ->action(fn () => $this->queueDepartmentSupervisorAssignments())->cancelParentActions(),
                ])
                ->modalSubmitActionLabel('Open evaluation')->action(function () {
                    app(EvaluationCycles::class)->publish(auth()->user(), $this->record);
                    $this->record->refresh();
                    Notification::make()->success()->title('Evaluation ready')->body($this->record->ends_at ? 'Students can respond during the selected schedule. Supervisor tasks have been prepared.' : 'Responses can start at the opening time and continue until you select Close Evaluation. Supervisor tasks have been prepared.')->send();
                }),
            $this->supervisorGapAction('resolveSupervisorGaps'),
            Action::make('addPreparedClasses')->label('Add Newly Prepared Classes')->icon('heroicon-o-plus-circle')->color('gray')
                ->visible(fn () => $this->record->status === 'published' && ! $this->record->ends_at?->isPast())
                ->modalHeading('Add newly prepared classes')->modalSubmitActionLabel('Add Selected Classes')
                ->modalAutofocus(false)->stickyModalHeader()->stickyModalFooter()->modalWidth('4xl')
                ->modalDescription('New classes use this evaluation’s Annex A and B. Existing classes, responses, dates, and individual supervisor choices are preserved.')
                ->fillForm(function () {
                    $this->classAdditionPreview = app(EvaluationClassAdditions::class)->preview(auth()->user(), filament()->getTenant(), $this->record->id);

                    return ['preview_token' => $this->classAdditionPreview['token'], 'class_ids' => array_column($this->classAdditionPreview['rows'], 'id')];
                })
                ->form([
                    Forms\Components\Hidden::make('preview_token')->required(),
                    Forms\Components\Placeholder::make('preview')->hiddenLabel()
                        ->content(fn () => view('filament.forms.evaluation-class-additions', ['preview' => $this->classAdditionPreview])),
                    Forms\Components\CheckboxList::make('class_ids')->label('Classes to add')->required()->minItems(1)->searchable()->bulkToggleable()
                        ->options(fn () => collect($this->classAdditionPreview['rows'] ?? [])->pluck('label', 'id')->all())
                        ->descriptions(fn () => collect($this->classAdditionPreview['rows'] ?? [])->mapWithKeys(fn ($row) => [$row['id'] => $row['subject'].' · '.$row['students'].' students · Supervisors: '.$row['supervisors'].($row['uses_default'] ? ' (department default)' : '')])->all())
                        ->extraAttributes(['style' => 'max-height: min(50vh, 28rem); overflow-y: auto'])
                        ->visible(fn () => ! empty($this->classAdditionPreview['rows'])),
                ])
                ->modalSubmitAction(fn (StaticAction $action) => $action->disabled(empty($this->classAdditionPreview['rows'])))
                ->action(function (array $data) {
                    app(EvaluationClassAdditions::class)->enqueue(auth()->user(), filament()->getTenant(), $this->record->id, $data['preview_token'], $data['class_ids'] ?? []);
                    Notification::make()->success()->title('New classes queued')->body('Classes and supervisor tasks will be added in the background. The bell will confirm completion.')->send();
                }),
            Action::make('departmentSupervisors')->label('Use Department Supervisor')->icon('heroicon-o-user-group')->color('gray')
                ->visible(fn () => $this->canUseDepartmentSupervisor())->requiresConfirmation()
                ->modalHeading('Fill missing supervisor assignments')
                ->modalDescription('Adds the saved department supervisor to classes in this evaluation that have no supervisor. Existing choices and additional supervisors are kept. Self-evaluation is skipped.')
                ->modalSubmitActionLabel('Fill Missing Supervisors')
                ->action(fn () => $this->queueDepartmentSupervisorAssignments()),
            ActionGroup::make([
                Action::make('monitor')->label('Student Progress')->visible(fn () => StudentEvaluationProgress::canAccess())->url(fn () => StudentEvaluationProgress::getUrl(['tableFilters' => ['department' => ['value' => $this->record->department_id], 'semester' => ['value' => $this->record->semester_id]]])),
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

    protected function canUseDepartmentSupervisor(): bool
    {
        return $this->record->status === 'draft' && (bool) $this->record->department?->supervisor_id
            && app(DepartmentSupervisorAssignments::class)->allowed(auth()->user(), filament()->getTenant());
    }

    protected function supervisorGapAction(string $name): Action
    {
        return Action::make($name)->label('Resolve Missing Supervisors')->icon('heroicon-o-user-plus')->color('gray')
            ->modalAutofocus(false)->stickyModalHeader()->stickyModalFooter()->modalWidth('3xl')
            ->visible(fn () => in_array($this->record->status, ['draft', 'published'], true)
                && app(DepartmentSupervisorAssignments::class)->allowed(auth()->user(), filament()->getTenant())
                && count(app(SupervisorAssignmentGaps::class)->gaps(auth()->user(), filament()->getTenant(), $this->record->id)) > 0)
            ->modalHeading('Choose supervisors once per faculty')->modalSubmitActionLabel('Assign to All Listed Classes')
            ->modalDescription('Each choice fills all missing subjects and sections for that faculty in this evaluation. Existing individual assignments are preserved. Suggestions reuse configured defaults or consistent existing choices; the department head’s evaluator is never guessed from a role.')
            ->fillForm(fn () => ['faculty_supervisors' => app(SupervisorAssignmentGaps::class)->gaps(auth()->user(), filament()->getTenant(), $this->record->id)])
            ->form([
                Forms\Components\Repeater::make('faculty_supervisors')->hiddenLabel()->addable(false)->deletable(false)->reorderable(false)->required()->schema([
                    Forms\Components\Hidden::make('faculty_id')->required(),
                    Forms\Components\Hidden::make('faculty_name')->dehydrated(false),
                    Forms\Components\Hidden::make('class_count')->dehydrated(false),
                    Forms\Components\Hidden::make('suggested')->dehydrated(false),
                    Forms\Components\Placeholder::make('context')->hiddenLabel()->content(fn (Forms\Get $get) => $get('faculty_name').' — '.$get('class_count').' classes'),
                    Forms\Components\Select::make('supervisor_ids')->label('Supervisor evaluators')->multiple()->required()->searchable()->preload()
                        ->options(fn (Forms\Get $get) => app(SupervisorAssignmentGaps::class)->options(auth()->user(), filament()->getTenant(), $this->record->id, (int) $get('faculty_id')))
                        ->helperText(fn (Forms\Get $get) => $get('suggested') ? 'Suggested from existing faculty assignments or the saved department default. Review or change this selection.' : 'Choose one or more authorized supervisors once for all listed classes. The faculty evaluated is excluded.'),
                ]),
            ])->action(function (array $data) {
                app(SupervisorAssignmentGaps::class)->enqueue(auth()->user(), filament()->getTenant(), $this->record->id, $data['faculty_supervisors']);
                Notification::make()->success()->title('Faculty supervisor assignments queued')->body('All listed class gaps will be filled in the background. Review again after the completion notification.')->send();
            });
    }

    protected function queueDepartmentSupervisorAssignments(): void
    {
        $cycle = $this->record->fresh();
        app(DepartmentSupervisorAssignments::class)->enqueue(auth()->user(), filament()->getTenant(), $cycle->department_id, $cycle->semester_id, $cycle->id);
        Notification::make()->success()->title('Supervisor assignments queued')->body('Missing supervisors are being filled in the background. When the bell confirms completion, choose Review & Open again.')->send();
    }
}
