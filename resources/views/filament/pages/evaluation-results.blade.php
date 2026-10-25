<x-filament-panels::page>
    @include('filament.partials.evaluation-workflow', ['currentStep' => 4])
    <p class="text-sm text-gray-600">Select a semester and teaching department. SET and SEF are separate ratings. Only complete responses contribute to scores; unanswered evaluations never count as zero. Scores, tallies, Annex C/D previews and CSV exports use this selection.</p>
    {{ $this->table }}
</x-filament-panels::page>
