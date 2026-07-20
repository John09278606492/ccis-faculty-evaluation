<x-filament-panels::page>
    <div class="fi-section rounded-xl bg-[#f5f0fa] dark:bg-[#1a0833]/30 border border-[#d4b8e8] dark:border-[#1f013d] p-3">
        <div class="flex items-start gap-2">
            <x-heroicon-o-information-circle class="w-5 h-5 text-[#340167] dark:text-[#c9a8e0] shrink-0 mt-0.5" />
            <p class="text-xs text-[#280150] dark:text-[#c9a8e0]">
                All evaluations are <strong>anonymous</strong>. You can view the scores and ratings for each
                submission,
                but the identity of evaluators is not disclosed.
            </p>
        </div>
    </div>

    {{ $this->table }}
</x-filament-panels::page>