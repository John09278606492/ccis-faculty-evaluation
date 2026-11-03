@php($workflowSteps = app(\App\Services\EvaluationWorkflow::class)->steps())
@if ($workflowSteps)
<nav aria-label="Evaluation workflow" data-evaluation-workflow class="rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900">
    <ol class="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm">
        @foreach ($workflowSteps as $step)
            <li class="flex items-center gap-3" @if (($currentStep ?? null) === $step['number']) aria-current="step" @endif>
                @unless($loop->first)<span aria-hidden="true" class="text-gray-400">→</span>@endunless
                <a href="{{ $step['links'][0]['url'] }}" wire:navigate
                    @class(['rounded-lg px-2 py-1', 'bg-primary-50 font-semibold text-primary-700 dark:bg-gray-800 dark:text-primary-400' => ($currentStep ?? null) === $step['number'], 'text-gray-600 hover:text-primary-600 dark:text-gray-400' => ($currentStep ?? null) !== $step['number']])>
                    {{ $step['title'] }}
                </a>
            </li>
        @endforeach
    </ol>
</nav>
@endif
