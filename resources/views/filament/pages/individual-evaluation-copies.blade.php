<x-filament-panels::page>
    @if($faculty)
        <p class="text-sm text-gray-600 dark:text-gray-300">Print one copy, select several, or print all filtered copies. Evaluator identities are hidden by default.</p>
    @endif
    {{ $this->table }}
</x-filament-panels::page>
