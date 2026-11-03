{{--
    Automatic checking of code answers against the reference solution
    (resources/js/components/code-checker.js). `single` checks one answer in the
    grading form; `bulk` checks every submitted answer when started.
--}}
<div
    x-load
    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('code-checker') }}"
    x-data="codeCheckerComponent({
        mode: @js($mode),
        runnerBaseUrl: @js(asset('code-runner')),
        questions: @js($questions),
        answers: @js($answers),
        pointsPath: @js($pointsPath),
    })"
    wire:ignore
    class="space-y-3 text-sm"
>
    <div x-ref="frames" aria-hidden="true"></div>

    @if ($mode === 'bulk')
        <div class="flex flex-wrap items-center gap-3">
            <x-filament::button x-on:click="start()" x-bind:disabled="state === 'running' || total === 0" icon="heroicon-o-play">
                <span x-text="state === 'done' ? 'Check again' : 'Start checking'"></span>
            </x-filament::button>
            <span class="text-gray-600 dark:text-gray-300" x-text="total === 0
                ? 'No submitted code answers to check yet.'
                : state === 'idle'
                    ? `${total} ${total === 1 ? 'answer' : 'answers'} to check. The first run loads PHP and can take a moment.`
                    : `Checked ${checked} of ${total}` + (state === 'done' ? `. Filled in ${applied} ${applied === 1 ? 'score' : 'scores'}.` : '…')"></span>
        </div>
    @else
        <p class="text-gray-600 dark:text-gray-300" x-show="state === 'running'">Running the tests… (the first run loads PHP and can take a moment)</p>
    @endif

    <p class="text-danger-600 dark:text-danger-400" x-show="state === 'error'" x-text="`The check stopped: ${error}`"></p>

    <template x-for="row in rows" :key="row.id">
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="font-medium" x-text="mode === 'bulk' ? row.label : `${row.passed} of ${row.total} tests match your solution`"></span>
                <span class="flex items-center gap-2">
                    <span x-bind:class="row.passed === row.total ? 'text-success-600 dark:text-success-400' : (row.passed === 0 ? 'text-danger-600 dark:text-danger-400' : 'text-warning-600 dark:text-warning-400')"
                        x-text="(mode === 'bulk' ? `${row.passed}/${row.total} tests · ` : 'Suggested ') + `${row.suggested} / ${questions[row.questionId].points} pts`"></span>
                    <span class="text-gray-500" x-show="mode === 'bulk' && ! row.applied">(kept your score)</span>
                    <x-filament::button size="xs" color="gray" x-show="mode === 'single'" x-on:click="useSuggested(row)">Use this score</x-filament::button>
                </span>
            </div>
            <ul class="mt-2 space-y-1">
                <template x-for="(testCase, index) in row.cases" :key="index">
                    <li>
                        <template x-if="testCase.passed">
                            <span><span class="text-success-600 dark:text-success-400">✓</span> <span x-text="testCase.label"></span></span>
                        </template>
                        <template x-if="! testCase.passed">
                            <details>
                                <summary class="cursor-pointer"><span class="text-danger-600 dark:text-danger-400">✕</span> <span x-text="testCase.label"></span> <span class="text-gray-500">(output differs)</span></summary>
                                <div class="mt-1 grid gap-2 sm:grid-cols-2">
                                    <div>
                                        <div class="text-xs text-gray-500">Your solution</div>
                                        <pre class="max-h-48 overflow-auto whitespace-pre-wrap rounded bg-gray-50 p-2 text-xs dark:bg-white/5" x-text="testCase.expected || '(no output)'"></pre>
                                    </div>
                                    <div>
                                        <div class="text-xs text-gray-500">Student</div>
                                        <pre class="max-h-48 overflow-auto whitespace-pre-wrap rounded bg-gray-50 p-2 text-xs dark:bg-white/5" x-text="testCase.actual || '(no output)'"></pre>
                                    </div>
                                </div>
                            </details>
                        </template>
                    </li>
                </template>
            </ul>
        </div>
    </template>
</div>
