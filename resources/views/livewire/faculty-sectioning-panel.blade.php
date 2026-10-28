<div class="space-y-4 text-sm" style="min-width:0;overflow-wrap:anywhere" @if(in_array($operationStatus, ['queued','processing'], true)) wire:poll.2s="refreshOperation" @endif>
    <p class="font-semibold">{{ $anchor->course->code }} — {{ $anchor->course->name }} · {{ $anchor->semester->name }}</p>
    <p>Choose the instructor and section, then assign enrolled students here. Sectioning is required when a subject has multiple instructors.</p>
    <details><summary class="cursor-pointer">Current sections and instructors</summary>
        <div style="max-height:180px;overflow:auto"><table class="w-full text-left"><thead><tr><th class="p-2">Section</th><th class="p-2">Instructor</th><th class="p-2">Students</th></tr></thead><tbody>
        @foreach($group as $class)<tr class="border-t"><td class="p-2">{{ $class->section ?: 'Not set' }}</td><td class="p-2">{{ $class->evaluatee?->name }}</td><td class="p-2">{{ $class->students_count }}</td></tr>@endforeach
        </tbody></table></div>
    </details>
    @if(!$operationId)
        {{ $this->form }}
        <x-filament::button type="button" wire:click="assign" wire:loading.attr="disabled" wire:target="assign">Assign section</x-filament::button>
        <p class="text-gray-500">Validation and assignment run in the background. You can keep this window open to see the result.</p>
    @else
        <div class="rounded-lg border p-4" role="status">
            @if(in_array($operationStatus, ['queued','processing'], true))
                <p>Validating and assigning this section… You can continue working. A bell notification will also confirm completion.</p>
            @else
                <p>{{ $operationError ?? $operationResult['message'] ?? 'Operation completed.' }}</p>
                @if($operationResult['issues'] ?? [])
                    <div style="max-height:260px;overflow:auto"><table class="w-full text-left"><thead><tr><th class="p-2">Row / ID</th><th class="p-2">Issue</th></tr></thead><tbody>
                        @foreach($operationResult['issues'] as $row)@if($row['messages'])<tr><td class="p-2">{{ $row['row'] }} / {{ $row['student_id'] }}</td><td class="p-2">{{ implode(' ', $row['messages']) }}</td></tr>@endif @endforeach
                    </tbody></table></div>
                    <x-filament::link :href="route('background.download', $operationId)" :spa-mode="false" download>Download validation report</x-filament::link>
                @endif
                <x-filament::button type="button" wire:click="anotherSection" class="mt-3">Assign another section</x-filament::button>
            @endif
        </div>
    @endif
    <x-filament-actions::modals />
</div>
