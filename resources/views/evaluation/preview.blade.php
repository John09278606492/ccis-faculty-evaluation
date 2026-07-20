<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview: {{ $form->name }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .rating-radio:checked+.rating-circle {
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
            ['value' => 5, 'label' => 'Outstanding', 'description' => 'The performance almost always exceeds the job requirements.', 'color' => '#22c55e'],
            ['value' => 4, 'label' => 'Very Satisfactory', 'description' => 'The performance meets and often exceeds the job requirements.', 'color' => '#3b82f6'],
            ['value' => 3, 'label' => 'Satisfactory', 'description' => 'The performance meets job requirements.', 'color' => '#eab308'],
            ['value' => 2, 'label' => 'Fair', 'description' => 'The performance needs some development.', 'color' => '#f97316'],
            ['value' => 1, 'label' => 'Poor', 'description' => 'The faculty fails to meet job requirements.', 'color' => '#ef4444'],
        ];
        $ratingScale = collect($ratingScale)->sortByDesc('value')->values();

        $scoringConfig = $form->scoring_config ?? [];
        $sectionMethod = $scoringConfig['section_method'] ?? 'average';
        $overallMethod = $scoringConfig['overall_method'] ?? 'average';
    @endphp

    <div class="max-w-5xl mx-auto px-4">
        <!-- Preview Banner -->
        <div class="bg-amber-500 text-white rounded-lg shadow-md p-4 mb-6 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                    </path>
                </svg>
                <div>
                    <p class="font-bold text-lg">Preview Mode</p>
                    <p class="text-amber-100 text-sm">This is how the form will appear to evaluators. Submissions are
                        disabled.</p>
                </div>
            </div>
            <a href="javascript:void(0)" onclick="window.close(); return false;"
                class="bg-white text-amber-600 font-semibold px-4 py-2 rounded-lg hover:bg-amber-50 transition text-sm">←
                Close Preview</a>
        </div>

        <!-- Header -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6 text-center">
            <h1 class="text-xl font-bold text-gray-800 mb-1">{{ $form->name }}</h1>
            @if($form->description)
                <p class="text-gray-600 text-sm mb-4">{{ $form->description }}</p>
            @endif
            <div class="bg-blue-50 border border-blue-200 p-4 rounded-lg text-left">
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div><span class="text-gray-500">Name of Evaluatee:</span> <span
                            class="font-semibold text-gray-400 italic">[Evaluatee Name]</span></div>
                    <div><span class="text-gray-500">Course:</span> <span
                            class="font-semibold text-gray-400 italic">[Course Code - Name]</span></div>
                    <div><span class="text-gray-500">Semester:</span> <span
                            class="font-semibold text-gray-400 italic">[Semester / School Year]</span></div>
                    <div><span class="text-gray-500">Section:</span> <span
                            class="font-semibold text-gray-400 italic">[Section]</span></div>
                </div>
            </div>
        </div>

        <!-- Rating Scale Legend (only when form has rating fields) -->
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
                                <td class="py-2 px-3 font-semibold" style="color: {{ $scale['color'] ?? '#374151' }}">
                                    {{ $scale['label'] }}
                                </td>
                                <td class="py-2 px-3 text-gray-600">{{ $scale['description'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        <!-- Scoring Method Info -->
        @if($hasRatingFields)
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-6 text-sm text-blue-800">
            <strong>Scoring:</strong>
            Section = {{ $sectionMethod === 'average' ? 'Average' : ($sectionMethod === 'sum' ? 'Sum' : 'Percentage') }}
            · Overall =
            {{ $overallMethod === 'average' ? 'Average of sections' : ($overallMethod === 'sum' ? 'Sum of sections' : ($overallMethod === 'grand_average' ? 'Grand average of all ratings' : 'Weighted average')) }}
        </div>
        @endif

        <!-- Form Fields -->
        @php
            $groupedFields = $fields->groupBy('section');
            $globalFieldNumber = 0;
        @endphp

        @foreach($groupedFields as $section => $sectionFields)
            @php
                $sectionHasLikert = $sectionFields->whereIn('type', ['likert_scale', 'rating'])->count() > 0;
                $sectionHasOther = $sectionFields->whereNotIn('type', ['likert_scale', 'rating'])->count() > 0;
                $likertCount = $sectionFields->whereIn('type', ['likert_scale', 'rating'])->count();
            @endphp

            <div class="bg-white rounded-lg shadow-md mb-6 overflow-hidden">
                <div class="bg-[#340167] text-white px-4 py-3">
                    <h3 class="font-semibold">{{ $section ?: 'General Questions' }}</h3>
                </div>

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
                                                <span class="font-bold text-lg"
                                                    style="color: {{ $scale['color'] ?? '#374151' }}">{{ $scale['value'] }}</span>
                                                <span
                                                    class="text-xs text-gray-500 leading-tight mt-0.5">{{ $scale['label'] }}</span>
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
                                        </td>
                                        @foreach($form->field_columns ?? [] as $col)
                                        <td class="py-3 px-4 text-gray-700 text-sm eval-richtext eval-col-uniform">
                                            {!! $field->column_values[$col['key']] ?? '' !!}
                                        </td>
                                        @endforeach
                                        @foreach($ratingScale as $scale)
                                            <td class="py-3 px-2 text-center">
                                                <label class="rating-label flex justify-center cursor-not-allowed"
                                                    style="--scale-color: {{ $scale['color'] ?? '#3b82f6' }}">
                                                    <input type="radio" name="preview_{{ $field->name }}" value="{{ $scale['value'] }}"
                                                        class="rating-radio sr-only" disabled>
                                                    <span
                                                        class="rating-circle w-9 h-9 flex items-center justify-center rounded-full border-2 border-gray-300 font-semibold text-sm text-gray-500 transition-all duration-150 opacity-75">
                                                        {{ $scale['value'] }}
                                                    </span>
                                                </label>
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <!-- Section Score placeholder -->
                    <div class="bg-gray-50 border-t-2 border-gray-200 px-4 py-3 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="font-semibold text-gray-700">Section Score:</span>
                            <span
                                class="text-sm text-gray-500">({{ $sectionMethod === 'average' ? 'Average' : ($sectionMethod === 'sum' ? 'Sum' : 'Percentage') }})</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-2xl font-bold text-gray-400">—</span>
                            <span class="text-sm font-semibold px-3 py-1 rounded-full bg-gray-200 text-gray-500">Not yet
                                rated</span>
                        </div>
                    </div>
                @endif

                @if($sectionHasOther)
                    <div class="divide-y divide-gray-200">
                        @foreach($sectionFields->where(fn($f) => !in_array($f->type, ['likert_scale', 'rating'])) as $field)
                            <div class="p-4">
                                <label class="block font-medium text-gray-800 mb-2">{{ $field->label }}
                                    @if($field->is_required)<span class="text-red-500">*</span>@endif</label>
                                @if($field->type === 'textarea')
                                    <textarea rows="3" class="w-full border border-gray-300 rounded-lg px-4 py-2 bg-gray-50"
                                        disabled></textarea>
                                @elseif($field->type === 'text')
                                    <input type="text" class="w-full border border-gray-300 rounded-lg px-4 py-2 bg-gray-50" disabled>
                                @elseif($field->type === 'select')
                                    <select class="w-full border border-gray-300 rounded-lg px-4 py-2 bg-gray-50" disabled>
                                        <option>Select...</option>
                                    </select>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach

        <!-- Overall Score placeholder (only when form has rating fields) -->
        @if($hasRatingFields)
        <div class="bg-white rounded-lg shadow-md p-6 mb-6 border-2 border-blue-200">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-bold text-gray-800">Overall Score</h3>
                    <p class="text-sm text-gray-500">Calculated after evaluation is submitted</p>
                </div>
                <div class="text-right">
                    <div class="text-3xl font-bold text-gray-400">—</div>
                    <div
                        class="text-sm font-semibold mt-1 px-4 py-1 rounded-full bg-gray-200 text-gray-500 inline-block">
                        Not yet rated</div>
                </div>
            </div>
        </div>
        @endif

        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <button type="button" disabled
                class="w-full bg-gray-400 text-white font-semibold py-3 px-6 rounded-lg cursor-not-allowed">
                Submit Evaluation (Disabled in Preview)
            </button>
        </div>

        <p class="text-center text-gray-400 text-sm mt-6">Your responses are anonymous and will be used to improve
            teaching quality.</p>
    </div>
</body>

</html>