{{-- Anonymous evaluation view - shows scores only, no evaluator information --}}
<div class="space-y-4">
    @if($submission)
        @php
            $form = $submission->form;
            $fields = $form->fields()->orderBy('order')->get();
            $data = $submission->data ?? [];

            $ratingScale = $form->rating_scale ?? [
                ['value' => 5, 'label' => 'Outstanding', 'color' => '#22c55e'],
                ['value' => 4, 'label' => 'Very Satisfactory', 'color' => '#3b82f6'],
                ['value' => 3, 'label' => 'Satisfactory', 'color' => '#eab308'],
                ['value' => 2, 'label' => 'Fair', 'color' => '#f97316'],
                ['value' => 1, 'label' => 'Poor', 'color' => '#ef4444'],
            ];
            $ratingScale = collect($ratingScale)->sortByDesc('value')->values();
            $maxScaleValue = $ratingScale->max('value') ?? 5;
            $scaleMap = $ratingScale->keyBy('value');

            $scoringConfig = $form->scoring_config ?? [];
            $sectionMethod = $scoringConfig['section_method'] ?? 'average';

            // Group fields by section
            $groupedFields = $fields->groupBy('section');

            // Helper: get rating info for a score
            $getRatingInfo = function ($score) use ($ratingScale) {
                if (!$score || !is_numeric($score))
                    return ['label' => '—', 'color' => '#6b7280'];
                $closest = $ratingScale->last();
                foreach ($ratingScale as $s) {
                    if ($score >= ($s['value'] - 0.5)) {
                        $closest = $s;
                        break;
                    }
                }
                return $closest;
            };

            // Calculate section scores
            $sectionScores = [];
            $allRatings = [];

            foreach ($groupedFields as $section => $sectionFields) {
                $ratings = [];
                foreach ($sectionFields->whereIn('type', ['likert_scale', 'rating']) as $field) {
                    $val = $data[$field->name] ?? null;
                    if ($val !== null && is_numeric($val)) {
                        $ratings[] = (float) $val;
                        $allRatings[] = (float) $val;
                    }
                }
                if (!empty($ratings)) {
                    $sum = array_sum($ratings);
                    $count = count($ratings);
                    switch ($sectionMethod) {
                        case 'sum':
                            $sectionScores[$section ?: 'General Questions'] = ['score' => $sum, 'count' => $count, 'avg' => $sum / $count];
                            break;
                        case 'percentage':
                            $maxPossible = $count * $maxScaleValue;
                            $sectionScores[$section ?: 'General Questions'] = ['score' => ($sum / $maxPossible) * 100, 'count' => $count, 'avg' => $sum / $count];
                            break;
                        default:
                            $sectionScores[$section ?: 'General Questions'] = ['score' => $sum / $count, 'count' => $count, 'avg' => $sum / $count];
                    }
                }
            }

            // Calculate overall
            $overallMethod = $scoringConfig['overall_method'] ?? 'average';
            $overallScore = 0;
            $overallAvg = 0;
            if (!empty($sectionScores)) {
                $scores = array_column($sectionScores, 'score');
                switch ($overallMethod) {
                    case 'sum':
                        $overallScore = array_sum($scores);
                        $overallAvg = !empty($allRatings) ? array_sum($allRatings) / count($allRatings) : 0;
                        break;
                    case 'grand_average':
                        $overallScore = !empty($allRatings) ? array_sum($allRatings) / count($allRatings) : 0;
                        $overallAvg = $overallScore;
                        break;
                    case 'weighted_average':
                        $weights = collect($scoringConfig['section_weights'] ?? []);
                        $weightMap = $weights->pluck('weight', 'section_name')->map(fn($w) => $w / 100);
                        $weightedSum = 0;
                        $totalWeight = 0;
                        foreach ($sectionScores as $name => $s) {
                            $w = $weightMap[$name] ?? (1 / count($sectionScores));
                            $weightedSum += $s['score'] * $w;
                            $totalWeight += $w;
                        }
                        $overallScore = $totalWeight > 0 ? $weightedSum / $totalWeight : 0;
                        $overallAvg = $overallScore;
                        break;
                    default:
                        $overallScore = array_sum($scores) / count($scores);
                        $overallAvg = $overallScore;
                }
            }
            $overallRating = $getRatingInfo($overallAvg);

            $methodLabels = [
                'average' => 'Average',
                'sum' => 'Sum',
                'percentage' => 'Percentage',
                'weighted_average' => 'Weighted Average',
                'grand_average' => 'Grand Average',
            ];
        @endphp

        {{-- Header - NO evaluator info --}}
        <div class="flex items-center justify-between border-b pb-3 dark:border-gray-700">
            <div>
                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $form->name }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Section: {{ $methodLabels[$sectionMethod] ?? $sectionMethod }} ·
                    Overall: {{ $methodLabels[$overallMethod] ?? $overallMethod }}
                </p>
            </div>
            <div class="text-right">
                <p class="text-xs text-gray-500 dark:text-gray-400">Submitted</p>
                <p class="text-xs font-medium text-gray-700 dark:text-gray-300">
                    {{ $submission->submitted_at?->format('M j, Y g:i A') }}
                </p>
            </div>
        </div>

        {{-- Overall Score Card --}}
        <div class="rounded-lg p-4 text-center"
            style="background-color: {{ $overallRating['color'] ?? '#6b7280' }}15; border: 1px solid {{ $overallRating['color'] ?? '#6b7280' }}30;">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Overall Score</p>
            <p class="text-3xl font-bold" style="color: {{ $overallRating['color'] ?? '#374151' }}">
                {{ $sectionMethod === 'percentage' ? number_format($overallScore, 1) . '%' : number_format($overallScore, 2) }}
            </p>
            <p class="text-sm font-semibold mt-0.5" style="color: {{ $overallRating['color'] ?? '#374151' }}">
                {{ $overallRating['label'] ?? '—' }}
            </p>
        </div>

        {{-- Section Scores --}}
        @if(count($sectionScores) > 1)
            <div class="space-y-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Section Breakdown</p>
                @foreach($sectionScores as $sectionName => $sData)
                    @php $sRating = $getRatingInfo($sData['avg']); @endphp
                    <div class="flex items-center justify-between bg-gray-50 dark:bg-gray-800 rounded-lg px-3 py-2">
                        <span class="text-sm text-gray-700 dark:text-gray-300 font-medium">{{ $sectionName }}</span>
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-bold" style="color: {{ $sRating['color'] ?? '#374151' }}">
                                {{ $sectionMethod === 'percentage' ? number_format($sData['score'], 1) . '%' : number_format($sData['score'], 2) }}
                            </span>
                            <span class="text-xs px-2 py-0.5 rounded-full font-semibold"
                                style="background-color: {{ $sRating['color'] ?? '#6b7280' }}20; color: {{ $sRating['color'] ?? '#6b7280' }}">
                                {{ $sRating['label'] ?? '—' }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Individual Scores by Section (only rating fields, no text/comment responses) --}}
        <div class="space-y-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Scores by Criteria</p>

            @foreach($groupedFields as $section => $sectionFields)
                @php
                    $ratingFieldsInSection = $sectionFields->whereIn('type', ['likert_scale', 'rating']);
                @endphp

                @if($ratingFieldsInSection->isNotEmpty())
                    <div class="rounded-lg border dark:border-gray-700 overflow-hidden">
                        <div class="bg-gray-800 dark:bg-gray-900 px-3 py-2">
                            <p class="text-xs font-semibold text-white">{{ $section ?: 'General Questions' }}</p>
                        </div>
                        <div class="divide-y dark:divide-gray-700">
                            @foreach($ratingFieldsInSection as $field)
                                @php
                                    $val = $data[$field->name] ?? null;
                                    $valRating = ($val !== null && is_numeric($val)) ? ($scaleMap[(int) $val] ?? null) : null;
                                    $fieldComment = $data['_field_comments'][$field->name] ?? null;
                                @endphp
                                <div class="{{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/30' : '' }}">
                                    <div class="flex items-center justify-between px-3 py-2">
                                        <span class="text-xs text-gray-700 dark:text-gray-300 pr-4 flex-1">{{ $field->label }}</span>
                                        @if($val !== null && is_numeric($val))
                                            <div class="flex items-center gap-1.5 shrink-0">
                                                <span
                                                    class="w-6 h-6 flex items-center justify-center rounded-full text-xs font-bold text-white"
                                                    style="background-color: {{ $valRating['color'] ?? '#6b7280' }}">
                                                    {{ $val }}
                                                </span>
                                                <span class="text-xs font-medium" style="color: {{ $valRating['color'] ?? '#6b7280' }}">
                                                    {{ $valRating['label'] ?? '' }}
                                                </span>
                                            </div>
                                        @else
                                            <span class="text-xs text-gray-400">—</span>
                                        @endif
                                    </div>
                                    @if($fieldComment)
                                        <div class="px-3 pb-2 -mt-0.5">
                                            <p
                                                class="text-[11px] text-gray-500 dark:text-gray-400 italic pl-2 border-l-2 border-blue-300 dark:border-blue-600">
                                                "{{ $fieldComment }}"</p>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        </div>

        {{-- Comments section - show text responses but anonymized --}}
        @php
            $commentFields = $fields->whereIn('type', ['text', 'textarea', 'long_text']);
            $hasComments = false;
            foreach ($commentFields as $cf) {
                if (!empty($data[$cf->name])) {
                    $hasComments = true;
                    break;
                }
            }
        @endphp

        @if($hasComments)
            <div class="space-y-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Comments</p>
                @foreach($commentFields as $field)
                    @php $comment = $data[$field->name] ?? null; @endphp
                    @if(!empty($comment))
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg px-3 py-2">
                            <p class="text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ $field->label }}</p>
                            <p class="text-sm text-gray-800 dark:text-gray-200 italic">"{{ $comment }}"</p>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif

        {{-- Overall Comment --}}
        @if(!empty($data['_overall_comment']))
            <div class="space-y-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Overall Comment</p>
                <div class="bg-blue-50/50 dark:bg-blue-900/10 rounded-lg px-3 py-2 border dark:border-gray-700">
                    <p class="text-sm text-gray-800 dark:text-gray-200 italic">"{{ $data['_overall_comment'] }}"</p>
                </div>
            </div>
        @endif

        {{-- Rating Scale Legend --}}
        <div class="pt-2 border-t dark:border-gray-700">
            <p class="text-xs text-gray-400 dark:text-gray-500 mb-2">Rating Scale</p>
            <div class="flex flex-wrap gap-2">
                @foreach($ratingScale as $scale)
                    <span class="inline-flex items-center gap-1 text-xs">
                        <span class="w-4 h-4 rounded-full flex items-center justify-center text-[10px] font-bold text-white"
                            style="background-color: {{ $scale['color'] ?? '#6b7280' }}">{{ $scale['value'] }}</span>
                        <span class="text-gray-500 dark:text-gray-400">{{ $scale['label'] }}</span>
                    </span>
                @endforeach
            </div>
        </div>
    @else
        <div class="text-center py-6">
            <x-heroicon-o-clipboard-document-list class="w-12 h-12 mx-auto text-gray-400 mb-2" />
            <p class="text-sm text-gray-500 dark:text-gray-400">No submission data found.</p>
        </div>
    @endif
</div>