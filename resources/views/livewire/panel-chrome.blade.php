<div data-live-panel data-live-kinds="setup users access settings" data-live-area="{{ $area }}" style="display:contents">
    @if($area === 'sidebar')
        <x-filament-panels::sidebar :navigation="$navigation" class="fi-main-sidebar" />
    @else
        <x-filament-panels::topbar :navigation="$navigation" />
    @endif
</div>
