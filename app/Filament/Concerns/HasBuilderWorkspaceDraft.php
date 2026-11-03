<?php

namespace App\Filament\Concerns;

use App\Filament\Pages\FormBuilder;
use App\Models\Form;
use App\Models\Quiz;
use App\Services\WorkspaceDrafts;
use Illuminate\Support\Arr;

trait HasBuilderWorkspaceDraft
{
    use HasWorkspaceDraft;

    protected function isInstrumentDraft(): bool
    {
        return $this instanceof FormBuilder;
    }

    public function workspaceDraftClientConfig(): array
    {
        return ['paths' => $this->isInstrumentDraft() ? ['selectedFieldId', 'selectedFieldData', 'selectedSectionId'] : ['selectedQuestionId', 'selectedQuestionData']];
    }

    protected function workspaceDraftRecordKey(): string
    {
        return (string) ($this->isInstrumentDraft() ? $this->formId : $this->quizId);
    }

    protected function workspaceDraftRecordRevision(): string
    {
        $record = $this->isInstrumentDraft() ? Form::findOrFail($this->formId) : Quiz::findOrFail($this->quizId);
        $items = $this->isInstrumentDraft() ? $record->fields() : $record->questions();

        return WorkspaceDrafts::hash(['record' => $record->attributesToArray(), 'items' => $items->orderBy('id')->get()->map->attributesToArray()->all()]);
    }

    protected function authorizeWorkspaceDraft(): void
    {
        if ($this->isInstrumentDraft()) {
            $this->formRecord = Form::findOrFail($this->formId);
            abort_unless($this->formRecord->campuses()->whereKey(filament()->getTenant())->exists(), 403);
            abort_unless(auth()->user()->can('update', $this->formRecord), 403);
            $this->formRecord->assertEditable();
        } else {
            $this->quizRecord = Quiz::findOrFail($this->quizId);
            abort_unless((int) $this->quizRecord->campus?->id === (int) filament()->getTenant()?->id, 403);
            $this->authorizeEditing();
        }
    }

    protected function cleanWorkspaceDraft(array $state): array
    {
        return $this->cleanDraftValues(Arr::only($state, $this->workspaceDraftClientConfig()['paths']));
    }

    protected function workspaceDraftState(): array
    {
        return $this->cleanWorkspaceDraft(collect($this->workspaceDraftClientConfig()['paths'])->mapWithKeys(fn ($key) => [$key => $this->{$key}])->all());
    }

    protected function applyWorkspaceDraft(array $state): void
    {
        if ($this->isInstrumentDraft()) {
            $field = $this->formRecord->fields()->findOrFail($state['selectedFieldId'] ?? null);
            $this->selectField($field->id);
            $this->selectedFieldData = array_replace($this->selectedFieldData, Arr::only($state['selectedFieldData'] ?? [], array_keys($this->selectedFieldData)));
            $this->selectedSectionId = $state['selectedSectionId'] ?? null;
        } else {
            $question = $this->quizRecord->questions()->findOrFail($state['selectedQuestionId'] ?? null);
            $this->selectedQuestionId = null;
            $this->selectQuestion($question->id);
            $this->selectedQuestionData = array_replace($this->selectedQuestionData, Arr::only($state['selectedQuestionData'] ?? [], array_keys($this->selectedQuestionData)));
        }
    }

    protected function reloadWorkspaceDraftForm(): void
    {
        if ($this->isInstrumentDraft()) {
            if ($this->selectedFieldId) {
                $this->selectField($this->selectedFieldId);
            }
        } elseif ($this->selectedQuestionId) {
            // Discard must not trigger the quiz builder's normal save-on-switch.
            $id = $this->selectedQuestionId;
            $this->selectedQuestionId = null;
            $this->selectQuestion($id);
        }
    }

    protected function stashBuilderWorkspaceDraft(): void
    {
        if ($this->workspaceDraftInitialized && ! $this->workspaceDraftAvailable && WorkspaceDrafts::hash($this->workspaceDraftState()) !== $this->workspaceDraftInitial) {
            $this->persistWorkspaceDraft($this->workspaceDraftState());
            $this->workspaceDraftAvailable = $this->workspaceDraftSavedAt !== null;
        }
    }

    protected function beginBuilderWorkspaceDraft(): void
    {
        if (! $this->workspaceDraftInitialized) {
            return;
        }
        $this->workspaceDraftInitial = WorkspaceDrafts::hash($this->workspaceDraftState());
        $this->workspaceDraftBase = $this->workspaceDraftRecordRevision();
        $this->dispatch('workspace-draft-focus', component: $this->getId(), state: $this->workspaceDraftState());
    }
}
