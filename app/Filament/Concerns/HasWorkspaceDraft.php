<?php

namespace App\Filament\Concerns;

use App\Services\WorkspaceDrafts;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait HasWorkspaceDraft
{
    #[Locked]
    public string $workspaceDraftContext = '';

    #[Locked]
    public ?string $workspaceDraftRevision = null;

    #[Locked]
    public string $workspaceDraftBase = '';

    #[Locked]
    public string $workspaceDraftInitial = '';

    #[Locked]
    public bool $workspaceDraftAvailable = false;

    #[Locked]
    public ?string $workspaceDraftSavedAt = null;

    protected bool $workspaceDraftInitialized = false;

    public function mountHasWorkspaceDraft(): void
    {
        if (! $this->shouldKeepWorkspaceDraft()) return;
        $this->workspaceDraftContext = static::class.':'.$this->workspaceDraftRecordKey();
        $this->workspaceDraftInitial = WorkspaceDrafts::hash($this->workspaceDraftState());
        $this->workspaceDraftBase = $this->workspaceDraftRecordRevision();
        $draft = $this->workspaceDraftStore()->find(auth()->user(), filament()->getTenant(), $this->workspaceDraftContext);
        $this->workspaceDraftRevision = $draft?->revision;
        $this->workspaceDraftAvailable = $draft && $draft->expires_at->isFuture() && filled($draft->payload);
        $this->workspaceDraftSavedAt = $this->workspaceDraftAvailable ? $draft->updated_at->toIso8601String() : null;
        $this->workspaceDraftInitialized = true;
    }

    public function hydrateHasWorkspaceDraft(): void
    {
        $this->workspaceDraftInitialized = $this->workspaceDraftContext !== '';
    }

    protected function shouldKeepWorkspaceDraft(): bool
    {
        return true;
    }

    public function persistWorkspaceDraft(array $state): array
    {
        $this->authorizeWorkspaceDraft();
        // An unrecovered draft must never be silently replaced by freshly loaded defaults.
        if ($this->workspaceDraftAvailable) {
            return ['blocked' => true];
        }
        $state = $this->cleanWorkspaceDraft($state);
        $payload = WorkspaceDrafts::hash($state) === $this->workspaceDraftInitial ? [] : $state;
        $draft = $this->workspaceDraftStore()->write(auth()->user(), filament()->getTenant(), $this->workspaceDraftContext, $payload, $this->workspaceDraftBase, $this->workspaceDraftRevision);
        $this->workspaceDraftRevision = $draft->revision;
        $this->workspaceDraftSavedAt = filled($payload) ? $draft->updated_at->toIso8601String() : null;

        return ['saved' => true, 'at' => $this->workspaceDraftSavedAt];
    }

    public function resumeWorkspaceDraft(): void
    {
        $this->authorizeWorkspaceDraft();
        $draft = $this->workspaceDraftStore()->find(auth()->user(), filament()->getTenant(), $this->workspaceDraftContext);
        if (! $draft || $draft->expires_at->isPast() || blank($draft->payload)) {
            throw ValidationException::withMessages(['workspaceDraft' => 'This draft has expired or was already completed. Reload this page.']);
        }
        if ($draft->base_revision !== $this->workspaceDraftRecordRevision()) {
            throw ValidationException::withMessages(['workspaceDraft' => 'The saved record changed after this draft was started. Discard this draft and use the current record.']);
        }
        $this->applyWorkspaceDraft($this->cleanWorkspaceDraft($draft->payload));
        $this->workspaceDraftRevision = $draft->revision;
        $this->workspaceDraftBase = $draft->base_revision;
        $this->workspaceDraftAvailable = false;
        $this->workspaceDraftSavedAt = $draft->updated_at->toIso8601String();
        $this->resetValidation('workspaceDraft');
        $this->dispatch('workspace-draft-resumed', component: $this->getId(), state: $this->workspaceDraftState());
    }

    public function discardWorkspaceDraft(): void
    {
        $this->authorizeWorkspaceDraft();
        // Discard removes the working copy, without saving it into the real record.
        $this->reloadWorkspaceDraftForm();
        $this->completeWorkspaceDraft();
        $this->resetValidation('workspaceDraft');
    }

    protected function completeWorkspaceDraft(): void
    {
        if (! $this->workspaceDraftInitialized) {
            return;
        }
        $this->workspaceDraftInitial = WorkspaceDrafts::hash($this->workspaceDraftState());
        $this->workspaceDraftBase = $this->workspaceDraftRecordRevision();
        $draft = $this->workspaceDraftStore()->write(auth()->user(), filament()->getTenant(), $this->workspaceDraftContext, [], $this->workspaceDraftBase, $this->workspaceDraftRevision, completed: true);
        $this->workspaceDraftRevision = $draft->revision;
        $this->workspaceDraftAvailable = false;
        $this->workspaceDraftSavedAt = null;
        $this->dispatch('workspace-draft-reset', component: $this->getId(), state: $this->workspaceDraftState());
    }

    protected function workspaceDraftStore(): WorkspaceDrafts
    {
        return app(WorkspaceDrafts::class);
    }

    public function workspaceDraftInitialStateForClient(): array
    {
        return $this->workspaceDraftState();
    }

    public function workspaceDraftHeading(): string
    {
        return 'Working draft';
    }

    /** Remove credentials and uploaded files even if they occur inside a repeater. */
    protected function cleanDraftValues(array $state, int $depth = 0): array
    {
        abort_if($depth > 20, 422);
        $result = [];
        foreach ($state as $key => $value) {
            if (preg_match('/password|secret|token|(^|_)pin($|_)|upload|attachment|avatar/i', (string) $key)) {
                continue;
            }
            if (is_array($value)) {
                $result[$key] = $this->cleanDraftValues($value, $depth + 1);
            } elseif (is_scalar($value) || $value === null) {
                if (is_string($value) && str_starts_with($value, 'livewire-file:')) {
                    continue;
                }
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
