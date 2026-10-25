<div data-live-panel class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm dark:border-gray-700 dark:bg-gray-900">
    <div><strong>{{ $campus?->name }}</strong>@if($departments)<span class="ml-2 text-gray-500">{{ $departments }}</span>@endif</div>
    <label class="flex items-center gap-2"><span class="text-gray-500">Academic period</span><select wire:model.live="semesterId" class="rounded-lg border-gray-300 bg-white text-sm text-gray-950 dark:border-gray-700 dark:bg-gray-900 dark:text-white" aria-label="Academic period">@forelse($periods as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@empty<option value="">No academic period configured</option>@endforelse</select></label>
    @if(! $hasActiveYear)
        <p data-active-year-warning role="status" class="w-full rounded-lg bg-warning-50 p-3 text-warning-800">There is no active School Year for this campus. An administrator needs to create or activate a School Year with valid dates to proceed with evaluations.</p>
    @endif
</div>
