<div data-workspace-draft wire:key="workspace-draft-{{ $this->getId() }}" class="text-xs text-gray-600 dark:text-gray-400" x-data="{ draftReady: false }">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div aria-live="polite">
            <span x-show="$wire.workspaceDraftAvailable">Unfinished draft available.</span>
            <span x-show="! $wire.workspaceDraftAvailable" data-draft-status wire:ignore>Drafts save automatically.</span>
            <p x-show="$wire.workspaceDraftAvailable" class="mt-1">Saved for 30 days. Select uploaded files again when resuming.</p>
        </div>
        <div class="flex gap-3">
            <x-filament::button size="sm" x-on:click="$wire.__instance.workspaceDraft?.resume()" wire:target="resumeWorkspaceDraft" wire:loading.attr="disabled" x-bind:disabled="! draftReady" x-show="$wire.workspaceDraftAvailable">Resume draft</x-filament::button>
            <x-filament::button size="sm" color="gray" x-on:click="$wire.__instance.workspaceDraft?.discard()" wire:target="discardWorkspaceDraft" wire:loading.attr="disabled" x-bind:disabled="! draftReady" x-show="$wire.workspaceDraftAvailable || $wire.workspaceDraftSavedAt">Discard draft</x-filament::button>
        </div>
    </div>
    @error('workspaceDraft') <p class="mt-2 text-danger-600" role="alert">{{ $message }}</p> @enderror
</div>
@script
<script>
    const component = $wire.__instance
    let destination = null
    // Guard menu clicks even while the draft module is loading for the first time.
    const waitForDraft = event => {
        event.preventDefault()
        destination = event.detail.url.toString()
    }
    document.addEventListener('livewire:navigate', waitForDraft)
    component.addCleanup(() => document.removeEventListener('livewire:navigate', waitForDraft))
    try {
        await import(@js(\Illuminate\Support\Facades\Vite::asset('resources/js/workspace-drafts.js')))
        if (component.el.isConnected) {
            window.mountWorkspaceDraft($wire, component, @js($this->workspaceDraftClientConfig()), @js($this->workspaceDraftInitialStateForClient()))
        }
    } catch {
        const status = component.el.querySelector('[data-draft-status]')
        if (status) status.textContent = 'Draft autosave is unavailable. Use Save changes before leaving.'
    } finally {
        document.removeEventListener('livewire:navigate', waitForDraft)
    }
    if (destination && component.el.isConnected) window.Livewire.navigate(destination)
</script>
@endscript
