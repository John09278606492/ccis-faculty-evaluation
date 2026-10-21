<x-filament-panels::page>
    <div class="fi-section rounded-xl bg-[#f5f0fa] dark:bg-[#1a0833]/30 border border-[#d4b8e8] dark:border-[#1f013d] p-3">
        <div class="flex items-start gap-2">
            <x-heroicon-o-information-circle class="w-5 h-5 text-[#340167] dark:text-[#c9a8e0] shrink-0 mt-0.5" />
            <p class="text-xs text-[#280150] dark:text-[#c9a8e0]">
                All evaluations are <strong>anonymous</strong>. Results open after an evaluation closes and at
                least {{ \App\Services\EvaluationResultsRelease::minimumResponses() }} students have responded, and
                they never show who answered or when.
            </p>
        </div>
    </div>

    @if (count($this->pendingOfferings))
        <x-filament::section heading="Not open yet" compact>
            <ul class="space-y-1 text-sm text-gray-600 dark:text-gray-300">
                @foreach ($this->pendingOfferings as $pending)
                    <li><strong>{{ $pending['label'] }}</strong>: {{ $pending['reason'] }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>