<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $form->name }} - Evaluation</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .rating-radio:checked + .rating-circle {
            background-color: var(--scale-color, #3b82f6);
            color: white;
            border-color: var(--scale-color, #3b82f6);
            transform: scale(1.15);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }
        .rating-label:hover .rating-circle {
            border-color: var(--scale-color, #3b82f6);
            background-color: color-mix(in srgb, var(--scale-color, #3b82f6) 10%, white);
        }
        .eval-richtext ul, .eval-richtext ol { padding-left: 1.5em; margin: 2px 0; }
        .eval-richtext ul { list-style-type: disc; }
        .eval-richtext ol { list-style-type: decimal; }
        .eval-richtext li { margin: 1px 0; }
        .eval-richtext p { margin: 2px 0; }
        .eval-table { border-collapse: collapse; }
        .eval-table th, .eval-table td { border: 1px solid #e5e7eb; }
        .eval-col-uniform { width: var(--col-width); min-width: 120px; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen py-8">
    @php
        $hasRatingFields = $fields->whereIn('type', ['likert_scale', 'rating'])->count() > 0;

        $ratingScale = $form->rating_scale ?? [
            ['value' => 5, 'label' => 'Outstanding', 'description' => 'The performance almost always exceeds the job requirements. The Faculty is an exceptional role model.', 'color' => '#22c55e'],
            ['value' => 4, 'label' => 'Very Satisfactory', 'description' => 'The performance meets and often exceeds the job requirements.', 'color' => '#3b82f6'],
            ['value' => 3, 'label' => 'Satisfactory', 'description' => 'The performance meets job requirements.', 'color' => '#eab308'],
            ['value' => 2, 'label' => 'Fair', 'description' => 'The performance needs some development to meet job requirements.', 'color' => '#f97316'],
            ['value' => 1, 'label' => 'Poor', 'description' => 'The faculty fails to meet job requirements.', 'color' => '#ef4444'],
        ];
        $ratingScale = collect($ratingScale)->sortByDesc('value')->values();
        $maxScaleValue = $ratingScale->max('value') ?? 5;

        $scoringConfig = $form->scoring_config ?? [];
        $sectionMethod = $scoringConfig['section_method'] ?? 'average';
        $overallMethod = $scoringConfig['overall_method'] ?? 'average';
        $sectionWeights = $scoringConfig['section_weights'] ?? [];
        $sectionCommentsMode = $scoringConfig['section_comments'] ?? 'optional';
        $fieldCommentsMode = $scoringConfig['field_comments'] ?? 'optional';
        $overallCommentMode = $scoringConfig['overall_comment'] ?? 'optional';
    @endphp

    <div class="max-w-5xl mx-auto px-4">
        <!-- Header -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6 text-center">
            <h1 class="text-xl font-bold text-gray-800 mb-1">{{ $form->name }}</h1>
            @if($form->description)
            <p class="text-gray-600 text-sm mb-4">{{ $form->description }}</p>
            @endif

            <div class="bg-[#f5f0fa] border border-[#d4b8e8] p-4 rounded-lg text-left">
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <span class="text-gray-500">Name of {{ $courseOffering->evaluatee_label ?? 'Faculty' }}:</span>
                        <span class="font-semibold">{{ $courseOffering->evaluatee->name ?? 'N/A' }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500">Course:</span>
                        <span class="font-semibold">{{ $courseOffering->course->code ?? '' }} - {{ $courseOffering->course->name ?? '' }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500">Semester:</span>
                        <span class="font-semibold">{{ $courseOffering->semester?->name }} {{ $courseOffering->semester?->schoolYear?->name }}</span>
                    </div>
                    @if($courseOffering->section)
                    <div>
                        <span class="text-gray-500">Section:</span>
                        <span class="font-semibold">{{ $courseOffering->section }}</span>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        @if($alreadySubmitted)
        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-6 rounded-lg">
            <h2 class="text-lg font-semibold text-yellow-800 mb-2">Already Submitted</h2>
            <p class="text-yellow-700 mb-4">You have already submitted an evaluation for this assignment.</p>
            <a
                href="{{ url()->previous() }}"
                class="inline-flex items-center gap-2 bg-[#340167] text-white font-semibold py-2 px-5 rounded-lg hover:bg-[#280150] transition"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Go Back
            </a>
        </div>
        @else
        <!-- Rating Scale Legend (only shown when form has rating/likert fields) -->
        @if($hasRatingFields)
        <div class="bg-white rounded-lg shadow-md p-4 mb-6">
            <h3 class="font-semibold text-gray-700 mb-3">Rating Scale</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="text-left py-2 px-3 w-16">Scale</th>
                            <th class="text-left py-2 px-3">Descriptive Rating</th>
                            <th class="text-left py-2 px-3">Qualitative Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($ratingScale as $scale)
                        <tr class="border-b last:border-b-0">
                            <td class="py-2 px-3 font-bold text-center">{{ $scale['value'] }}</td>
                            <td class="py-2 px-3 font-semibold" style="color: {{ $scale['color'] ?? '#374151' }}">{{ $scale['label'] }}</td>
                            <td class="py-2 px-3 text-gray-600">{{ $scale['description'] ?? '' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        <!-- Form -->
        <form action="{{ route('evaluate.store', $courseOffering) }}" method="POST">
            @csrf

            @if(session('error'))
            <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-6 rounded">
                <p class="text-red-700">{{ session('error') }}</p>
            </div>
            @endif

            @php
                $groupedFields = $fields->groupBy('section');
                $sectionIndex = 0;
                $globalFieldNumber = 0;
            @endphp

            @foreach($groupedFields as $section => $sectionFields)
                @php
                    $sectionHasLikert = $sectionFields->whereIn('type', ['likert_scale', 'rating'])->count() > 0;
                    $sectionHasOther = $sectionFields->whereNotIn('type', ['likert_scale', 'rating'])->count() > 0;
                    $sectionId = 'section-' . $sectionIndex;
                    $sectionIndex++;
                @endphp

                <div class="bg-white rounded-lg shadow-md mb-6 overflow-hidden" data-section="{{ $section ?: 'General Questions' }}">
                    <!-- Section Header -->
                    <div class="bg-[#340167] text-white px-4 py-3 flex items-center justify-between">
                        <h3 class="font-semibold">{{ $section ?: 'General Questions' }}</h3>
                    </div>

                    {{-- Likert Scale Fields as Table --}}
                    @if($sectionHasLikert)
                    <div class="overflow-x-auto">
                        @php $colCount = count($form->field_columns ?? []); @endphp
                        <table class="w-full eval-table" @if($colCount) style="--col-width: {{ floor(40 / $colCount) }}%" @endif>
                            <thead>
                                <tr class="bg-gray-50 border-b-2 border-gray-200">
                                    <th class="text-left py-3 px-4 font-semibold text-gray-700" @if(!$colCount) style="width:50%" @endif>Criteria</th>
                                    @foreach($form->field_columns ?? [] as $col)
                                    <th class="text-left py-3 px-4 font-semibold text-gray-700 eval-col-uniform">{{ $col['label'] }}</th>
                                    @endforeach
                                    @foreach($ratingScale as $scale)
                                    <th class="py-3 px-2 text-center w-20">
                                        <div class="flex flex-col items-center">
                                            <span class="font-bold text-lg" style="color: {{ $scale['color'] ?? '#374151' }}">{{ $scale['value'] }}</span>
                                            <span class="text-xs text-gray-500 leading-tight mt-0.5">{{ $scale['label'] }}</span>
                                        </div>
                                    </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($sectionFields->where(fn($f) => in_array($f->type, ['likert_scale', 'rating'])) as $index => $field)
                                @php $globalFieldNumber++; @endphp
                                <tr class="border-b hover:bg-gray-50 {{ $loop->even ? 'bg-gray-50/50' : '' }}">
                                    <td class="py-3 px-4 text-gray-800">
                                        <span class="font-medium">{{ $globalFieldNumber }}.</span>
                                        {{ $field->label }}
                                        @if($field->is_required)<span class="text-red-500">*</span>@endif
                                        @if($field->help_text)
                                        <p class="text-gray-500 text-xs mt-0.5">{{ $field->help_text }}</p>
                                        @endif
                                        @error("fields.{$field->name}")
                                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                        @enderror
                                    </td>
                                    @foreach($form->field_columns ?? [] as $col)
                                    <td class="py-3 px-4 text-gray-700 text-sm eval-richtext eval-col-uniform">
                                        {!! $field->column_values[$col['key']] ?? '' !!}
                                    </td>
                                    @endforeach
                                    @foreach($ratingScale as $scale)
                                    <td class="py-3 px-2 text-center">
                                        <label class="rating-label flex justify-center cursor-pointer" style="--scale-color: {{ $scale['color'] ?? '#3b82f6' }}">
                                            <input
                                                type="radio"
                                                name="fields[{{ $field->name }}]"
                                                value="{{ $scale['value'] }}"
                                                class="rating-radio sr-only"
                                                data-section="{{ $sectionId }}"
                                                @if($field->is_required) required @endif
                                            >
                                            <span class="rating-circle w-9 h-9 flex items-center justify-center rounded-full border-2 border-gray-300 font-semibold text-sm text-gray-500 transition-all duration-150">
                                                {{ $scale['value'] }}
                                            </span>
                                        </label>
                                    </td>
                                    @endforeach
                                </tr>
                                @if($fieldCommentsMode !== 'hidden')
                                <tr class="border-b bg-gray-50/30">
                                    <td colspan="{{ count($ratingScale) + 1 }}" class="px-4 py-2">
                                        <div class="flex items-start gap-2">
                                            <svg class="w-4 h-4 text-gray-400 mt-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/></svg>
                                            <textarea
                                                name="field_comments[{{ $field->name }}]"
                                                rows="1"
                                                placeholder="Comment on this item{{ $fieldCommentsMode === 'required' ? ' (required)' : ' (optional)' }}..."
                                                class="w-full border border-gray-200 rounded px-2 py-1 text-xs text-gray-600 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 resize-y bg-white/80"
                                                @if($fieldCommentsMode === 'required') required @endif
                                            ></textarea>
                                        </div>
                                        @error("field_comments.{$field->name}")
                                        <p class="text-red-500 text-xs mt-1 ml-6">{{ $message }}</p>
                                        @enderror
                                    </td>
                                </tr>
                                @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <!-- Section Score -->
                    <div class="bg-gray-50 border-t-2 border-gray-200 px-4 py-3 flex items-center justify-between" id="{{ $sectionId }}-score-bar">
                        <div class="flex items-center gap-2">
                            <span class="font-semibold text-gray-700">Section Score:</span>
                            <span class="text-sm text-gray-500">
                                ({{ $sectionMethod === 'average' ? 'Average' : ($sectionMethod === 'sum' ? 'Sum' : 'Percentage') }})
                            </span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span id="{{ $sectionId }}-score" class="text-2xl font-bold text-gray-400">—</span>
                            <span id="{{ $sectionId }}-rating" class="text-sm font-semibold px-3 py-1 rounded-full bg-gray-200 text-gray-500">Not yet rated</span>
                        </div>
                    </div>
                    @endif

                    {{-- Non-Likert Fields --}}
                    @if($sectionHasOther)
                    <div class="divide-y divide-gray-200">
                        @foreach($sectionFields->where(fn($f) => !in_array($f->type, ['likert_scale', 'rating'])) as $field)
                        <div class="p-4 hover:bg-gray-50">
                            <label class="block font-medium text-gray-800 mb-2">
                                {{ $field->label }}
                                @if($field->is_required)<span class="text-red-500">*</span>@endif
                            </label>
                            @if($field->help_text)
                            <p class="text-gray-500 text-sm mb-2">{{ $field->help_text }}</p>
                            @endif

                            @if($field->type === 'textarea')
                            <textarea
                                name="fields[{{ $field->name }}]"
                                rows="3"
                                placeholder="{{ $field->placeholder }}"
                                class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                @if($field->is_required) required @endif
                            ></textarea>
                            @elseif($field->type === 'text')
                            <input
                                type="text"
                                name="fields[{{ $field->name }}]"
                                placeholder="{{ $field->placeholder }}"
                                class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500"
                                @if($field->is_required) required @endif
                            >
                            @elseif($field->type === 'select')
                            <select
                                name="fields[{{ $field->name }}]"
                                class="w-full border border-gray-300 rounded-lg px-4 py-2"
                                @if($field->is_required) required @endif
                            >
                                <option value="">Select...</option>
                                @foreach($field->options ?? [] as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                            @elseif($field->type === 'radio')
                            <div class="flex flex-wrap gap-4">
                                @foreach($field->options ?? [] as $option)
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input
                                        type="radio"
                                        name="fields[{{ $field->name }}]"
                                        value="{{ $option['value'] }}"
                                        class="w-4 h-4"
                                        @if($field->is_required) required @endif
                                    >
                                    <span>{{ $option['label'] }}</span>
                                </label>
                                @endforeach
                            </div>
                            @endif

                            @error("fields.{$field->name}")
                            <p class="text-red-500 text-sm mt-2">{{ $message }}</p>
                            @enderror

                            @if($fieldCommentsMode !== 'hidden')
                            <div class="mt-2 flex items-start gap-2">
                                <svg class="w-4 h-4 text-gray-400 mt-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/></svg>
                                <textarea
                                    name="field_comments[{{ $field->name }}]"
                                    rows="1"
                                    placeholder="Comment on this item{{ $fieldCommentsMode === 'required' ? ' (required)' : ' (optional)' }}..."
                                    class="w-full border border-gray-200 rounded px-2 py-1 text-xs text-gray-600 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 resize-y bg-white/80"
                                    @if($fieldCommentsMode === 'required') required @endif
                                ></textarea>
                            </div>
                            @error("field_comments.{$field->name}")
                            <p class="text-red-500 text-xs mt-1 ml-6">{{ $message }}</p>
                            @enderror
                            @endif
                        </div>
                        @endforeach
                    </div>
                    @endif
                </div>
            @endforeach

            <!-- Overall Score (only when form has rating fields) -->
            @if($hasRatingFields)
            <div class="bg-white rounded-lg shadow-md p-6 mb-6 border-2 border-blue-200" id="overall-score-card">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-gray-800">Overall Score</h3>
                        <p class="text-sm text-gray-500">
                            {{ $overallMethod === 'average' ? 'Average of section scores' : ($overallMethod === 'sum' ? 'Sum of section scores' : ($overallMethod === 'grand_average' ? 'Average of all individual ratings' : 'Weighted average of section scores')) }}
                        </p>
                    </div>
                    <div class="text-right">
                        <div id="overall-score" class="text-3xl font-bold text-gray-400">—</div>
                        <div id="overall-rating" class="text-sm font-semibold mt-1 px-4 py-1 rounded-full bg-gray-200 text-gray-500 inline-block">Not yet rated</div>
                    </div>
                </div>
            </div>
            @endif

            {{-- Overall Comment (configurable: hidden/optional/required) --}}
            @if($overallCommentMode !== 'hidden')
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <label class="block text-base font-semibold text-gray-800 mb-2">
                    Overall Comments
                    @if($overallCommentMode === 'required')
                        <span class="text-red-500">*</span>
                    @else
                        <span class="text-gray-400 font-normal text-sm">(optional)</span>
                    @endif
                </label>
                <p class="text-sm text-gray-500 mb-3">Share any overall feedback about the {{ strtolower($courseOffering->evaluatee_label ?? 'faculty') }} or the course.</p>
                <textarea
                    name="overall_comment"
                    rows="4"
                    placeholder="Write your overall comments here..."
                    class="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 resize-y"
                    @if($overallCommentMode === 'required') required @endif
                ></textarea>
                @error('overall_comment')
                <p class="text-red-500 text-sm mt-2">{{ $message }}</p>
                @enderror
            </div>
            @endif

            <div class="bg-white rounded-lg shadow-md p-6">
                <button
                    type="submit"
                    class="w-full bg-[#340167] text-white font-semibold py-3 px-6 rounded-lg hover:bg-[#280150] transition"
                >
                    Submit Evaluation
                </button>
            </div>
        </form>
        @endif

        <p class="text-center text-gray-400 text-sm mt-6">
            Your responses are anonymous and will be used to improve teaching quality.
        </p>
    </div>

    @if(!$alreadySubmitted && $hasRatingFields)
    <script>
        const scoringConfig = {
            sectionMethod: @json($sectionMethod),
            overallMethod: @json($overallMethod),
            sectionWeights: @json($sectionWeights),
            maxScale: {{ $maxScaleValue }},
            ratingScale: @json($ratingScale->toArray()),
        };

        // Get rating label & color for a score
        function getRatingInfo(score) {
            if (!score || isNaN(score)) return { label: 'Not yet rated', color: '#6b7280', bg: '#e5e7eb' };
            const scale = scoringConfig.ratingScale;
            let closest = scale[scale.length - 1];
            for (const s of scale) {
                if (score >= (parseFloat(s.value) - 0.5)) {
                    closest = s;
                    break;
                }
            }
            return { label: closest.label, color: closest.color || '#374151', bg: closest.color ? closest.color + '22' : '#e5e7eb' };
        }

        // Calculate section scores
        function calculateScores() {
            const sections = {};

            // Gather all rated values per section
            document.querySelectorAll('.rating-radio:checked').forEach(radio => {
                const sectionId = radio.dataset.section;
                if (!sections[sectionId]) sections[sectionId] = [];
                sections[sectionId].push(parseFloat(radio.value));
            });

            // Also find total field count per section
            const sectionFieldCounts = {};
            document.querySelectorAll('.rating-radio').forEach(radio => {
                const sectionId = radio.dataset.section;
                const name = radio.name;
                if (!sectionFieldCounts[sectionId]) sectionFieldCounts[sectionId] = new Set();
                sectionFieldCounts[sectionId].add(name);
            });

            const sectionScores = {};
            const sectionElements = document.querySelectorAll('[data-section]');
            const sectionNames = [];

            sectionElements.forEach((el, idx) => {
                const sectionId = 'section-' + idx;
                const sectionName = el.dataset.section;
                sectionNames.push({ id: sectionId, name: sectionName });

                const values = sections[sectionId] || [];
                const totalFields = sectionFieldCounts[sectionId] ? sectionFieldCounts[sectionId].size : 0;

                const scoreEl = document.getElementById(sectionId + '-score');
                const ratingEl = document.getElementById(sectionId + '-rating');

                if (!scoreEl) return; // no likert fields in section

                if (values.length === 0) {
                    scoreEl.textContent = '—';
                    scoreEl.className = 'text-2xl font-bold text-gray-400';
                    ratingEl.textContent = 'Not yet rated';
                    ratingEl.style.backgroundColor = '#e5e7eb';
                    ratingEl.style.color = '#6b7280';
                    sectionScores[sectionId] = null;
                    return;
                }

                let score;
                const sum = values.reduce((a, b) => a + b, 0);

                switch (scoringConfig.sectionMethod) {
                    case 'sum':
                        score = sum;
                        break;
                    case 'percentage':
                        const maxPossible = totalFields * scoringConfig.maxScale;
                        score = maxPossible > 0 ? (sum / maxPossible) * 100 : 0;
                        break;
                    case 'average':
                    default:
                        score = values.length > 0 ? sum / values.length : 0;
                        break;
                }

                sectionScores[sectionId] = score;
                const displayScore = scoringConfig.sectionMethod === 'percentage'
                    ? score.toFixed(1) + '%'
                    : score.toFixed(2);

                scoreEl.textContent = displayScore;
                scoreEl.className = 'text-2xl font-bold';

                // Get rating for this score (use raw average for rating lookup if method is sum/percentage)
                const avgForRating = scoringConfig.sectionMethod === 'average'
                    ? score
                    : (values.length > 0 ? sum / values.length : 0);
                const info = getRatingInfo(avgForRating);
                scoreEl.style.color = info.color;
                ratingEl.textContent = info.label;
                ratingEl.style.backgroundColor = info.bg;
                ratingEl.style.color = info.color;
            });

            // Calculate overall score
            calculateOverall(sectionScores, sectionNames, sections, sectionFieldCounts);
        }

        function calculateOverall(sectionScores, sectionNames, rawSections, sectionFieldCounts) {
            const overallScoreEl = document.getElementById('overall-score');
            const overallRatingEl = document.getElementById('overall-rating');

            const validScores = Object.entries(sectionScores).filter(([_, v]) => v !== null);

            if (validScores.length === 0) {
                overallScoreEl.textContent = '—';
                overallScoreEl.className = 'text-3xl font-bold text-gray-400';
                overallRatingEl.textContent = 'Not yet rated';
                overallRatingEl.style.backgroundColor = '#e5e7eb';
                overallRatingEl.style.color = '#6b7280';
                return;
            }

            let overall;
            let avgForRating;

            switch (scoringConfig.overallMethod) {
                case 'sum':
                    overall = validScores.reduce((a, [_, v]) => a + v, 0);
                    // For rating: use average of all values
                    const allValsSum = Object.values(rawSections).flat();
                    avgForRating = allValsSum.length > 0 ? allValsSum.reduce((a, b) => a + b, 0) / allValsSum.length : 0;
                    break;

                case 'weighted_average':
                    const weights = {};
                    (scoringConfig.sectionWeights || []).forEach(w => {
                        weights[w.section_name] = parseFloat(w.weight) / 100;
                    });
                    let weightedSum = 0;
                    let totalWeight = 0;
                    sectionNames.forEach(s => {
                        if (sectionScores[s.id] !== null) {
                            const w = weights[s.name] || (1 / sectionNames.length);
                            weightedSum += sectionScores[s.id] * w;
                            totalWeight += w;
                        }
                    });
                    overall = totalWeight > 0 ? weightedSum / totalWeight : 0;
                    avgForRating = overall;
                    break;

                case 'grand_average':
                    const allValues = Object.values(rawSections).flat();
                    overall = allValues.length > 0 ? allValues.reduce((a, b) => a + b, 0) / allValues.length : 0;
                    avgForRating = overall;
                    break;

                case 'average':
                default:
                    overall = validScores.reduce((a, [_, v]) => a + v, 0) / validScores.length;
                    avgForRating = overall;
                    break;
            }

            const displayScore = scoringConfig.sectionMethod === 'percentage' && scoringConfig.overallMethod !== 'grand_average'
                ? overall.toFixed(1) + '%'
                : overall.toFixed(2);

            overallScoreEl.textContent = displayScore;
            overallScoreEl.className = 'text-3xl font-bold';

            const info = getRatingInfo(avgForRating);
            overallScoreEl.style.color = info.color;
            overallRatingEl.textContent = info.label;
            overallRatingEl.style.backgroundColor = info.bg;
            overallRatingEl.style.color = info.color;
        }

        // Listen for all radio changes
        document.querySelectorAll('.rating-radio').forEach(radio => {
            radio.addEventListener('change', calculateScores);
        });
    </script>
    @endif
</body>
</html>
