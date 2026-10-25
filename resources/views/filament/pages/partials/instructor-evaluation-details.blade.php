<div class="space-y-4">
    @if(empty($courseOfferings))
        <div class="text-center py-8">
            <x-heroicon-o-chart-bar class="w-12 h-12 mx-auto text-gray-400 mb-2" />
            <p class="text-sm text-gray-500 dark:text-gray-400">No evaluation data found for this person.</p>
        </div>
    @else
        {{-- Overall Summary --}}
        @php
            $allScores = collect($courseOfferings)->pluck('overall_score')->filter(fn($v) => $v > 0);
            $completeCount = collect($courseOfferings)->sum('submission_count');
            $grandAvg = $completeCount ? collect($courseOfferings)->sum(fn($row) => ($row['overall_score'] ?? 0) * $row['submission_count']) / $completeCount : 0;
            $totalEvals = collect($courseOfferings)->sum('submission_count');
            $firstCo = $courseOfferings[0] ?? [];
            $ratingScale = $firstCo['rating_scale'] ?? [];
            $sectionMethod = $firstCo['section_method'] ?? 'average';

            $grandColor = '#6b7280';
            $grandRating = '—';
            $scaleForGrand = collect($ratingScale)->sortByDesc('value');
            $checkScore = $sectionMethod === 'percentage' ? ($grandAvg / 100) * (collect($ratingScale)->max('value') ?? 5) : $grandAvg;
            foreach ($scaleForGrand as $s) {
                if ($checkScore >= ($s['value'] - 0.5)) {
                    $grandRating = $s['label'];
                    $grandColor = $s['color'] ?? '#6b7280';
                    break;
                }
            }
            if ($grandRating === '—' && $scaleForGrand->isNotEmpty()) {
                $last = $scaleForGrand->last();
                $grandRating = $last['label'] ?? '—';
                $grandColor = $last['color'] ?? '#6b7280';
            }

            $scoreSuffix = $sectionMethod === 'percentage' ? '%' : '';
            $methodLabels = [
                'average' => 'Average',
                'sum' => 'Sum',
                'percentage' => 'Percentage',
                'weighted_average' => 'Weighted Average',
                'grand_average' => 'Grand Average',
            ];
        @endphp

        @if(collect($courseOfferings)->pluck('evaluation_type')->unique()->count() > 1)
        <div class="rounded-lg border p-4 text-sm text-gray-600 dark:text-gray-300">Results include different evaluation types. Review each assignment below, or open Preview / Print Report for separate type summaries.</div>
        @else
        <div class="rounded-lg p-5 text-center"
            style="background-color: {{ $grandColor }}10; border: 2px solid {{ $grandColor }}30;">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">Complete-response Mean / 5</p>
            <p class="text-4xl font-bold" style="color: {{ $grandColor }}">
                {{ number_format($grandAvg, 2) }}{{ $scoreSuffix }}
            </p>
            <p class="text-sm font-semibold mt-1" style="color: {{ $grandColor }}">{{ $grandRating }}</p>
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-2">Based on {{ $totalEvals }}
                evaluation{{ $totalEvals !== 1 ? 's' : '' }} across {{ count($courseOfferings) }}
                course{{ count($courseOfferings) !== 1 ? 's' : '' }}</p>
        </div>

        {{-- Per-Course Results --}}
        @endif
        <div class="space-y-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Results by Course</p>

            @foreach($courseOfferings as $co)
                <div class="rounded-lg border dark:border-gray-700 overflow-hidden">
                    {{-- Course Header --}}
                    <div class="bg-gray-100 dark:bg-gray-800 px-4 py-3 flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-indigo-600">{{ $co['evaluation_type'] ?? 'Other / Unspecified' }}</p>
                            <span class="text-sm font-bold text-gray-900 dark:text-white">{{ $co['course_code'] }}</span>
                            <span class="text-xs text-gray-600 dark:text-gray-300 ml-2">{{ $co['course_name'] }}</span>
                            @if($co['section'] !== '—')
                                <span class="text-xs text-gray-500 dark:text-gray-400 ml-2">· Section {{ $co['section'] }}</span>
                            @endif
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-gray-400">{{ $co['submission_count'] }} complete
                                eval{{ $co['submission_count'] !== 1 ? 's' : '' }}</span>
                            <span class="text-sm font-bold px-2 py-0.5 rounded-full"
                                style="background-color: {{ $co['rating_color'] }}20; color: {{ $co['rating_color'] }}">
                                {{ $co['overall_score'] !== null ? number_format($co['overall_score'], 2) . ' / 5 · ' . number_format($co['percentage'], 2) . ' / 100' : 'Pending' }} ·
                                {{ $co['overall_rating'] }}
                            </span>
                        </div>
                    </div>

                    {{-- Semester + Scoring Method --}}
                    <div
                        class="px-4 py-1.5 bg-gray-50 dark:bg-gray-800/50 border-b dark:border-gray-700 flex items-center justify-between">
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $co['semester'] }} ·
                            {{ $co['school_year'] }}</span>
                        <span class="text-xs text-gray-400 dark:text-gray-500">
                            {{ $co['form_name'] }} · {{ $co['incomplete_count'] ?? 0 }} incomplete · Raw {{ number_format($co['raw_total'] ?? 0, 2) }} / {{ number_format($co['maximum'] ?? 0, 0) }}
                        </span>
                    </div>

                    {{-- Section Scores Summary --}}
                    @if(count($co['section_scores'] ?? []) > 1)
                        <div class="px-4 py-2 bg-gray-100/50 dark:bg-gray-800/60 border-b dark:border-gray-700">
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                                @foreach($co['section_scores'] as $sectionName => $sScore)
                                    @php
                                        $sCheckScore = $co['section_method'] === 'percentage' ? ($sScore / 100) * $co['max_scale'] : $sScore;
                                        $sColor = '#6b7280';
                                        foreach (collect($co['rating_scale'])->sortByDesc('value') as $s) {
                                            if ($sCheckScore >= ($s['value'] - 0.5)) {
                                                $sColor = $s['color'] ?? '#6b7280';
                                                break;
                                            }
                                        }
                                    @endphp
                                    <div class="flex items-center justify-between bg-white dark:bg-gray-900 rounded px-2 py-1.5">
                                        <span class="text-xs text-gray-600 dark:text-gray-400 truncate pr-2">{{ $sectionName }}</span>
                                        <span class="text-xs font-bold shrink-0"
                                            style="color: {{ $sColor }}">{{ number_format($sScore, 2) }}{{ $co['score_suffix'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Field Averages grouped by section --}}
                    @php
                        $sectionGroups = collect($co['field_averages'])->groupBy('section');
                        $scaleMap = collect($co['rating_scale'])->keyBy('value');
                        $maxScale = $co['max_scale'] ?? 5;
                    @endphp

                    @foreach($sectionGroups as $section => $fields)
                        <div class="border-b dark:border-gray-700 last:border-b-0">
                            <div class="px-4 py-2 bg-gray-100/50 dark:bg-gray-800/80 flex items-center justify-between">
                                <span class="text-xs font-semibold text-gray-600 dark:text-gray-300">{{ $section }}</span>
                                @if(isset($co['section_scores'][$section]))
                                    <span
                                        class="text-xs font-bold text-gray-700 dark:text-gray-200">{{ number_format($co['section_scores'][$section], 2) }}{{ $co['score_suffix'] }}</span>
                                @endif
                            </div>
                            <div class="divide-y dark:divide-gray-700/50">
                                @foreach($fields as $field)
                                    @php
                                        $pct = ($field['avg'] / $maxScale) * 100;
                                        $closest = collect($co['rating_scale'])->sortByDesc('value')->first(fn($s) => $field['avg'] >= ($s['value'] - 0.5));
                                        $barColor = $closest['color'] ?? '#6b7280';
                                    @endphp
                                    <div class="px-4 py-2">
                                        <div class="flex items-center justify-between mb-1">
                                            <span
                                                class="text-xs text-gray-600 dark:text-gray-400 flex-1 pr-3">{{ $field['label'] }}</span>
                                            <div class="flex items-center gap-2 shrink-0">
                                                <span class="text-xs text-gray-400">{{ $field['count'] }} responses</span>
                                                <span class="text-xs font-bold"
                                                    style="color: {{ $barColor }}">{{ number_format($field['avg'], 2) }}</span>
                                            </div>
                                        </div>
                                        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5">
                                            <div class="h-1.5 rounded-full transition-all"
                                                style="width: {{ $pct }}%; background-color: {{ $barColor }};"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    {{-- Evaluator Comments Section --}}
                    @if(!empty($co['tally']['questions']))
                        @include('filament.pages.partials.evaluator-tally', ['tally' => $co['tally']])
                    @endif
                    @php
                        $fieldComments = $co['field_comments'] ?? [];
                        $overallComments = $co['overall_comments'] ?? [];
                        $hasAnyComments = !empty($fieldComments) || !empty($overallComments);

                        // Map field names to labels for display
                        $fieldLabelMap = collect($co['field_averages'] ?? [])->pluck('label', 'label')->toArray();
                    @endphp

                    @if($hasAnyComments)
                        <div class="border-t dark:border-gray-700">
                            <div class="px-4 py-2 bg-[#f5f0fa] dark:bg-[#1a0833]/20 flex items-center gap-2">
                                <svg class="w-4 h-4 text-[#340167]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z" />
                                </svg>
                                <span
                                    class="text-xs font-semibold text-[#340167] dark:text-[#c9a8e0] uppercase tracking-wide">Evaluator
                                    Comments</span>
                            </div>

                            {{-- Per-field comments --}}
                            @if(!empty($fieldComments))
                                <div class="divide-y dark:divide-gray-700/50">
                                    @foreach($fieldComments as $fieldName => $comments)
                                        @php
                                            // Try to find the field label
                                            $fieldLabel = collect($co['field_averages'] ?? [])->firstWhere('label', $fieldName)['label']
                                                ?? $fieldName;
                                            // Also try matching by looking up from all fields
                                            $matchedField = collect($co['field_averages'] ?? [])->first(function ($f) use ($fieldName) {
                                                return str_contains(strtolower(str_replace(' ', '_', $f['label'])), strtolower($fieldName))
                                                    || strtolower($fieldName) === strtolower(str_replace(' ', '_', $f['label']));
                                            });
                                            if ($matchedField) {
                                                $fieldLabel = $matchedField['label'];
                                            }
                                        @endphp
                                        <div class="px-4 py-2">
                                            <p class="text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">{{ $fieldLabel }}</p>
                                            <div class="space-y-1 pl-2">
                                                @foreach($comments as $comment)
                                                    <p
                                                        class="text-xs text-gray-600 dark:text-gray-300 italic border-l-2 border-blue-200 dark:border-blue-700 pl-2">
                                                        "{{ $comment }}"</p>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            {{-- Overall comments --}}
                            @if(!empty($overallComments))
                                <div class="px-4 py-2 border-t dark:border-gray-700">
                                    <p class="text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Overall Comments</p>
                                    <div class="space-y-1 pl-2">
                                        @foreach($overallComments as $comment)
                                            <p
                                                class="text-xs text-gray-600 dark:text-gray-300 italic border-l-2 border-green-200 dark:border-green-700 pl-2">
                                                "{{ $comment }}"</p>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
