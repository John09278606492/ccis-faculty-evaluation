<?php

namespace App\Filament\Concerns;

use App\Services\WorkspaceDrafts;
use Illuminate\Support\Arr;

trait HasEvaluatorSelectionDraft
{
    use HasWorkspaceDraft;

    public function workspaceDraftHeading(): string
    {
        return 'Evaluator selection draft';
    }

    protected function shouldKeepWorkspaceDraft(): bool
    {
        return auth()->user() && filament()->getTenant() && auth()->user()->canAccessTenant(filament()->getTenant())
            && static::canViewForRecord($this->getOwnerRecord(), $this->getPageClass()) && $this->canAttach();
    }

    public function workspaceDraftClientConfig(): array
    {
        return ['paths' => ['mountedTableActionsData'], 'action' => 'assignSections'];
    }

    protected function workspaceDraftRecordKey(): string
    {
        return (string) $this->getOwnerRecord()->getKey().':sections';
    }

    protected function workspaceDraftRecordRevision(): string
    {
        $class = $this->getOwnerRecord()->fresh();
        $state = ['class' => $class->attributesToArray(), 'students' => $class->students()->orderBy('users.id')->pluck('users.id')->all()];
        $supervisors = $class->supervisorEvaluators()->orderBy('users.id')->pluck('users.id')->all();
        if ($supervisors !== []) {
            $state['supervisor_evaluators'] = $supervisors;
        }

        return WorkspaceDrafts::hash($state);
    }

    protected function authorizeWorkspaceDraft(): void
    {
        abort_unless(static::canViewForRecord($this->getOwnerRecord()->fresh(), $this->getPageClass()), 403);
        $this->authorizeRosterChange();
    }

    protected function workspaceDraftDefaults(): array
    {
        $saved = $this->getOwnerRecord()->fresh()->evaluator_selection ?? [];
        $trusted = ($saved['source'] ?? null) === 'sections_v1';
        $include = $trusted ? ($saved['include'] ?? []) : [];
        $exclude = $trusted ? ($saved['exclude'] ?? []) : [];

        return ['sections' => $saved['sections'] ?? [], 'include' => $include, 'exclude' => $exclude,
            'supervisor_ids' => $this->getOwnerRecord()->supervisorEvaluators()->pluck('users.id')->all(),
            'use_exceptions' => filled($include) || filled($exclude)];
    }

    protected function cleanWorkspaceDraft(array $state): array
    {
        $data = Arr::only((array) ($state['mountedTableActionsData'][0] ?? []), ['sections', 'include', 'exclude', 'use_exceptions', 'supervisor_ids']);
        $data = array_replace(['sections' => [], 'include' => [], 'exclude' => [], 'use_exceptions' => false, 'supervisor_ids' => []], $data);
        if (! $data['use_exceptions']) {
            $data['include'] = $data['exclude'] = [];
        }

        return ['mountedTableActionsData' => [$this->cleanDraftValues($data)]];
    }

    protected function workspaceDraftState(): array
    {
        return $this->cleanWorkspaceDraft(['mountedTableActionsData' => $this->mountedTableActions === ['assignSections'] ? $this->mountedTableActionsData : [$this->workspaceDraftDefaults()]]);
    }

    protected function applyWorkspaceDraft(array $state): void
    {
        if ($this->mountedTableActions !== ['assignSections']) {
            $this->mountTableAction('assignSections');
        }
        abort_unless($this->mountedTableActions === ['assignSections'], 403);
        $this->getMountedTableActionForm()->fill($state['mountedTableActionsData'][0]);
    }

    protected function reloadWorkspaceDraftForm(): void
    {
        if ($this->mountedTableActions === ['assignSections']) {
            $this->getMountedTableActionForm()->fill($this->workspaceDraftDefaults());
        }
    }

    public function unmountTableAction(bool $shouldCancelParentActions = true, bool $shouldCloseModal = true): void
    {
        // Closing a modal is also navigation. Its deferred inputs have reached
        // the component at this point, so capture them before Filament resets them.
        if ($this->workspaceDraftInitialized && $this->mountedTableActions === ['assignSections'] && array_key_exists('sections', $this->mountedTableActionsData[0] ?? []) && ! $this->workspaceDraftAvailable) {
            $this->persistWorkspaceDraft($this->workspaceDraftState());
            $this->workspaceDraftAvailable = $this->workspaceDraftSavedAt !== null;
        }
        parent::unmountTableAction($shouldCancelParentActions, $shouldCloseModal);
    }
}
