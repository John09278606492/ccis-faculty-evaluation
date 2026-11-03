<x-filament-panels::page>
    @include('filament.partials.evaluation-workflow', ['currentStep' => 5])
    <p class="text-sm text-gray-600">Choose a faculty member to view scores, tallies or printable reports. Only completed evaluations count toward ratings.</p>
    {{ $this->table }}
</x-filament-panels::page>
