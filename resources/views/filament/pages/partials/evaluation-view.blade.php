<div class="space-y-4">
    @if($submission)
        @php
            $instrument = app(\App\Services\EvaluationInstrument::class)->forSubmission($submission);
            $score = app(\App\Services\EvaluationScoring::class)->response($instrument, $submission);
            $fields = $instrument->fields->sortBy('order')->where('type', '!=', 'section_header');
            $data = $submission->data ?? [];
            $maximum = collect($instrument->rating_scale ?: [['value' => 5]])->max('value');
        @endphp
        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            <p class="text-sm font-semibold">{{ $instrument->name }}</p>
            @if($score)
                <p class="mt-2 text-xl font-bold text-primary-600">{{ number_format($score['mean_5'], 2) }} / 5 · {{ number_format($score['percentage'], 2) }} / 100</p>
                <p class="text-sm text-gray-500">Raw total: {{ number_format($score['raw_total'], 2) }} / {{ number_format($score['maximum'], 0) }}</p>
            @else
                <p class="mt-2 text-sm text-amber-700">Incomplete response — excluded from ratings.</p>
            @endif
        </div>
        @foreach($fields->groupBy(fn ($field) => $field->section ?: 'General Questions') as $section => $questions)
            <div class="rounded-lg border border-gray-200 dark:border-gray-700">
                <h3 class="border-b border-gray-200 px-4 py-2 text-sm font-semibold dark:border-gray-700">{{ $section }}</h3>
                <dl class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($questions as $field)
                        <div class="flex items-start justify-between gap-4 px-4 py-3 text-sm">
                            <dt>{{ $field->question_text }}</dt>
                            @php($value = $data[$field->name] ?? null)
                            <dd class="shrink-0 font-semibold">{{ is_array($value) ? implode(', ', $value) : ($value ?? 'Unanswered') }}{{ in_array($field->type, ['rating', 'likert_scale']) && $value !== null ? ' / '.$maximum : '' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endforeach
        @if(!empty($data['_overall_comment']))
            <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700"><h3 class="text-sm font-semibold">Overall Comments</h3><p class="mt-2 whitespace-pre-wrap text-sm">{{ $data['_overall_comment'] }}</p></div>
        @endif
    @else
        <p class="text-sm text-gray-500">No evaluation response available.</p>
    @endif
</div>
