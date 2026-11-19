<div class="flex flex-wrap items-center gap-2">
    @if(\App\Filament\Pages\GettingStarted::canAccess())
        <x-filament::button tag="a" :href="\App\Filament\Pages\GettingStarted::getUrl()" color="gray" outlined size="sm" icon="heroicon-o-academic-cap">Start here</x-filament::button>
    @endif
    @if($context = app(\App\Services\GuidedHelp::class)->forCurrentPage())
        <x-guided-help :context="$context" />
    @endif
</div>
