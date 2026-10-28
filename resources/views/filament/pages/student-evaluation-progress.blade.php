<x-filament-panels::page>
    @include('filament.partials.evaluation-workflow', ['currentStep' => 5])
    <p class="text-sm text-gray-600">Check completion by section or student. Ratings and comments remain private.</p>
    <div wire:key="progress-table-{{ $viewMode }}">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
