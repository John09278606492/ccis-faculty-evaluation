@props(['context', 'compact' => false])
@if(app(\App\Services\GuidedHelp::class)->allowed($context))
    <div data-guided-help="{{ $context }}" class="shrink-0">
        <x-filament::button type="button" color="gray" size="sm" icon="heroicon-o-question-mark-circle"
            x-data="{ waiting: false }" x-bind:disabled="waiting"
            x-on:filament-tour::loaded-elements.window="waiting = false"
            x-on:click="if (!document.body.classList.contains('driver-active')) { waiting = true; Livewire.dispatch('campus-guide::start', { context: $el.closest('[data-guided-help]').dataset.guidedHelp }); setTimeout(() => waiting = false, 8000); }"
            aria-label="Guide me: {{ app(\App\Services\GuidedHelp::class)->title($context) }}">Guide me</x-filament::button>
    </div>
@endif
