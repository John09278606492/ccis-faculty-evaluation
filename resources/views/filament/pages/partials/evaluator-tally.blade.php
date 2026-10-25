<div class="border-t dark:border-gray-700 px-4 py-4">
    <h4 class="text-sm font-semibold text-gray-900 dark:text-white">Per-Evaluator Rating Breakdown</h4>
    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 mb-3">Anonymous response labels apply to this assignment only. Names, IDs and submission times are omitted. Total is the sum of question ratings.</p>
    <div style="overflow-x:auto;max-height:420px">
        <table class="w-full text-xs" style="border-collapse:collapse;white-space:nowrap">
            <thead><tr class="bg-gray-100 dark:bg-gray-800"><th class="px-3 py-2 text-left">Evaluator</th>@foreach($tally['questions'] as $question)<th class="px-3 py-2 text-center" title="{{ $question['label'] }}">Q{{ $question['number'] }}</th>@endforeach<th class="px-3 py-2 text-center">Total / {{ $tally['maximum'] }}</th><th class="px-3 py-2 text-center">Average / {{ $tally['scale_maximum'] }}</th><th class="px-3 py-2 text-center">Rating (%)</th></tr></thead>
            <tbody>@foreach($tally['rows'] as $row)<tr class="border-t dark:border-gray-700"><th class="px-3 py-2 text-left font-medium">{{ $row['label'] }}</th>@foreach($row['values'] as $value)<td class="px-3 py-2 text-center">{{ $value === null ? '—' : $value + 0 }}</td>@endforeach<td class="px-3 py-2 text-center font-semibold">{{ $row['total'] === null ? 'Incomplete' : $row['total'] + 0 }}</td><td class="px-3 py-2 text-center font-semibold">{{ $row['average'] === null ? '—' : number_format($row['average'], 2) }}</td><td class="px-3 py-2 text-center">{{ $row['percentage'] === null ? '—' : number_format($row['percentage'], 2).'%' }}</td></tr>@endforeach</tbody>
        </table>
    </div>
    <details class="mt-3 text-xs text-gray-600 dark:text-gray-300"><summary style="cursor:pointer">Question guide (Q1, Q2…)</summary><ol class="mt-2 space-y-1">@foreach($tally['questions'] as $question)<li><b>Q{{ $question['number'] }}:</b> {{ $question['label'] }}</li>@endforeach</ol></details>
</div>
