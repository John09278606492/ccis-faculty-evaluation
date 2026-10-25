<div x-data="{ selectedReport: 'overview', reportOptions: @js($reportOptions) }" x-id="['faculty-report-selector']" class="space-y-4">
    <div class="sticky top-0 z-10 rounded-lg bg-white p-3 ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700">
        <label :for="$id('faculty-report-selector')" class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">Report</label>
        <x-filament::input.wrapper>
            <x-filament::input.select data-live-readonly-control x-model="selectedReport" x-bind:id="$id('faculty-report-selector')">
                <option value="overview">Scores &amp; Tally — Overview</option>
                @foreach($reportOptions as $key => $option)
                    <option value="{{ $key }}">{{ $option['label'] }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Choose a report for {{ $instructor->name }} in the current reporting period.</p>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Annex C/D include the paired student and supervisor results from the same evaluation. Scores and Tally follow your selected type and form version.</p>
    </div>

    <div x-show="selectedReport === 'overview'">
        @include('filament.pages.partials.instructor-evaluation-details', ['instructor' => $instructor, 'courseOfferings' => $courseOfferings])
    </div>

    <template x-if="selectedReport !== 'overview'">
        <div>
            @include('reports.preview-frame', [
                'urlExpression' => 'reportOptions[selectedReport].url',
                'title' => 'Faculty report print preview',
                'hint' => 'Use Print / Save PDF inside the preview. Scores and Tally use landscape paper; Annex C and D use portrait paper.',
            ])
        </div>
    </template>
</div>
