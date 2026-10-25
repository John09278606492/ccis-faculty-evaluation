@php($workflowSteps = app(\App\Services\EvaluationWorkflow::class)->steps())
@if ($workflowSteps)
<section class="evaluation-workflow" aria-label="Faculty evaluation steps" data-evaluation-workflow>
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 class="font-semibold text-gray-950 dark:text-white">Faculty evaluation: follow these steps</h2>
        <span class="text-xs text-gray-500">Use the campus and academic period selected above.</span>
    </div>
    <ol class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ($workflowSteps as $step)
            <li @if (($currentStep ?? null) === $step['number']) aria-current="step" @endif
                @class(['rounded-xl border p-4', 'border-primary-500 bg-primary-50 dark:bg-gray-900' => ($currentStep ?? null) === $step['number'], 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900' => ($currentStep ?? null) !== $step['number']])>
                <a href="{{ $step['links'][0]['url'] }}" wire:navigate class="flex items-center gap-2 font-semibold text-gray-950 dark:text-white"><span class="text-primary-600">{{ $step['number'] }}.</span> {{ $step['title'] }}</a>
                @if ($expanded ?? false)<p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $step['description'] }}</p>@endif
                @if ($expanded ?? false)
                <div class="mt-3 flex flex-wrap gap-x-3 gap-y-2">
                    @foreach ($step['links'] as $link)
                        <a href="{{ $link['url'] }}" wire:navigate class="text-sm font-medium text-primary-600 underline underline-offset-2 dark:text-primary-400">{{ $link['label'] }}</a>
                    @endforeach
                </div>
                @endif
            </li>
        @endforeach
    </ol>
    @if (! ($expanded ?? false))
        @foreach ($workflowSteps as $step)
            @if (($currentStep ?? null) === $step['number'] && count($step['links']) > 1)
                <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                    <span class="text-gray-500">In this step:</span>
                    @foreach ($step['links'] as $link)
                        <a href="{{ $link['url'] }}" wire:navigate class="font-medium text-primary-600 underline underline-offset-2 dark:text-primary-400">{{ $link['label'] }}</a>
                    @endforeach
                </div>
            @endif
        @endforeach
    @endif
</section>
@endif
