<x-filament-panels::page>
    @include('filament.partials.evaluation-workflow', ['currentStep' => 4])
    <p class="text-sm text-gray-600">Use By Section to compare completion and open follow-ups, then View Students to see who still needs to finish. Filters follow your authorized campus, departments and academic period. Students without assignments are listed separately; future or closed evaluations are not counted as open follow-ups. Students in several sections appear in each section. Ratings and comments remain private.</p>
    <div wire:key="progress-table-{{ $viewMode }}">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
