<div data-workspace-draft wire:key="workspace-draft-{{ $this->getId() }}" class="rounded-xl border border-primary-200 bg-primary-50 p-4 text-sm text-gray-900 dark:border-primary-800 dark:bg-gray-900 dark:text-gray-100" x-data="{ draftReady: false }">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div aria-live="polite">
            <strong>{{ $this->workspaceDraftHeading() }}</strong>
            <span x-show="$wire.workspaceDraftAvailable"> — You have unfinished changes here.</span>
            <span x-show="! $wire.workspaceDraftAvailable" data-draft-status wire:ignore>Changes are kept here as you work.</span>
            <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">Private to your account in this campus. A draft does not submit or publish anything. Kept for 30 days; uploaded files must be selected again.</p>
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
    await import(@js(\Illuminate\Support\Facades\Vite::asset('resources/js/workspace-drafts.js')))
    if (component.el.isConnected) {
        window.mountWorkspaceDraft($wire, component, @js($this->workspaceDraftClientConfig()), @js($this->workspaceDraftInitialStateForClient()))
    }
    document.removeEventListener('livewire:navigate', waitForDraft)
    if (destination && component.el.isConnected) window.Livewire.navigate(destination)
</script>
@endscript
