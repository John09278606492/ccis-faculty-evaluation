<x-filament-panels::page
    @class([
        'fi-resource-create-record-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
    ])
>
    @if (method_exists($this, 'getEvaluationWorkflowStep') && $this->getEvaluationWorkflowStep())
        @include('filament.partials.evaluation-workflow', ['currentStep' => $this->getEvaluationWorkflowStep()])
    @endif
    @if (method_exists($this, 'workspaceDraftClientConfig'))
        @include('filament.partials.workspace-draft')
    @endif
    <x-filament-panels::form
        id="form"
        :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()"
        wire:submit="create"
    >
        {{ $this->form }}

        <x-filament-panels::form.actions
            :actions="$this->getCachedFormActions()"
            :full-width="$this->hasFullWidthFormActions()"
        />
    </x-filament-panels::form>

    <x-filament-panels::page.unsaved-data-changes-alert />
</x-filament-panels::page>
