<?php

namespace App\Filament\Concerns;

use App\Services\WorkspaceDrafts;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Arr;

trait HasResourceFormDraft
{
    use HasWorkspaceDraft;

    public function workspaceDraftHeading(): string
    {
        return ucfirst(static::getResource()::getModelLabel()).' details draft';
    }

    public function workspaceDraftClientConfig(): array
    {
        return ['paths' => ['data'], 'keys' => ['data' => $this->workspaceDraftKeys()]];
    }

    protected function workspaceDraftKeys(): array
    {
        $keys = [];
        $blocked = [];
        foreach ($this->form->getFlatFields(withHidden: true) as $path => $field) {
            $key = explode('.', $path)[0];
            if ($field instanceof BaseFileUpload || ($field instanceof TextInput && $field->isPassword())) {
                $blocked[] = $key;
            } else {
                $keys[] = $key;
            }
        }
        return array_values(array_diff(array_unique($keys), $blocked));
    }

    protected function cleanWorkspaceDraft(array $state): array
    {
        return ['data' => $this->cleanDraftValues(Arr::only((array) ($state['data'] ?? []), $this->workspaceDraftKeys()))];
    }

    protected function workspaceDraftState(): array
    {
        return $this->cleanWorkspaceDraft(['data' => $this->data]);
    }

    protected function applyWorkspaceDraft(array $state): void
    {
        $this->form->fill(array_replace($this->data ?? [], $state['data']));
    }

    protected function workspaceDraftRecordKey(): string
    {
        return $this instanceof \Filament\Resources\Pages\CreateRecord ? 'new' : (string) $this->getRecord()->getKey();
    }

    protected function workspaceDraftRecordRevision(): string
    {
        if ($this instanceof \Filament\Resources\Pages\CreateRecord) {
            return WorkspaceDrafts::hash(['new' => static::class]);
        }
        return WorkspaceDrafts::hash($this->getRecord()->fresh()?->attributesToArray() ?? []);
    }

    protected function authorizeWorkspaceDraft(): void
    {
        if (! ($this instanceof \Filament\Resources\Pages\CreateRecord)) {
            $this->record = $this->resolveRecord($this->getRecord()->getKey());
        }
        $this->authorizeAccess();
        if (is_object($this->record) && method_exists($this->record, 'assertEditable')) {
            $this->record->assertEditable();
        }
    }

    protected function reloadWorkspaceDraftForm(): void
    {
        $this->fillForm();
        // Reset the regular unsaved-changes guard as well.
        parent::rememberData();
    }

    protected function rememberData(): void
    {
        parent::rememberData();
        $this->completeWorkspaceDraft();
    }
}
