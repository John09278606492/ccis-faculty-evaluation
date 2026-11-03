<div class="space-y-4 text-sm">
    <p><strong>{{ $review['course'] }} · {{ $review['class_label'] }}</strong> · {{ $review['faculty'] }}</p>
    <p>Academic period: {{ $review['academic_period'] }}</p>
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        @foreach(['total_rows' => 'Student rows', 'matched_students' => 'Matched students', 'error_count' => 'Rows to correct', 'duplicate_rows' => 'Repeated rows'] as $key => $label)
            <div class="rounded-lg border border-gray-200 p-3"><p class="text-gray-500">{{ $label }}</p><p class="text-xl font-semibold">{{ number_format($review[$key]) }}</p></div>
        @endforeach
    </div>
    <p>Matched sections: @foreach($review['sections'] as $section => $count)<span class="mr-3">{{ $section }} ({{ $count }})</span>@endforeach</p>
    @if(isset($review['comparison']))
        @php($c = $review['comparison'])
        <div class="space-y-3 rounded-lg border border-gray-200 p-3">
            <p class="font-semibold">Compared with {{ $review['class_label'] }} today
                @if($review['file_titles'] ?? [])<span class="font-normal text-gray-500">· the file calls it {{ implode(', ', $review['file_titles']) }}</span>@endif</p>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                @foreach(['In the section now' => $c['current'], 'Valid in this file' => $c['in_file'], 'Already in the section' => $c['already'], 'New to the section' => count($c['new'])] as $label => $value)
                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5"><p class="text-gray-500">{{ $label }}</p><p class="text-xl font-semibold">{{ number_format($value) }}</p></div>
                @endforeach
            </div>
            @foreach(['new' => ['Will be added', 'text-success-700'], 'missing' => ['In the section but not in this file (kept; remove them in the section if they left)', 'text-warning-600']] as $key => [$title, $color])
                @if($c[$key])
                    <details @if($key === 'new' && count($c['new']) <= 15) open @endif><summary class="cursor-pointer {{ $color }}">{{ $title }}: {{ count($c[$key]) }}</summary>
                        <p class="mt-1 text-gray-600">@foreach($c[$key] as $s)<span class="mr-3 inline-block">{{ $s['id'] }} {{ $s['name'] }}</span>@endforeach</p></details>
                @endif
            @endforeach
            @foreach($c['elsewhere'] as $group)
                <details><summary class="cursor-pointer text-warning-600">{{ count($group['students']) }} new students are already in {{ $group['section'] }}; they will be in both sections</summary>
                    <p class="mt-1 text-gray-600">@foreach($group['students'] as $s)<span class="mr-3 inline-block">{{ $s['id'] }} {{ $s['name'] }}</span>@endforeach</p></details>
            @endforeach
            @foreach($c['similar'] as $other)
                <p class="text-warning-600">Look-alike section {{ $other['section'] }} has {{ $other['students'] }} students, {{ $other['in_file'] }} of them in this file. If they are the same section, use Merge on {{ $other['section'] }} in Sections instead of keeping both.</p>
            @endforeach
        </div>
    @endif
    @if($review['nothing_new'] ?? false)
        <p class="font-semibold text-gray-700">Nothing to upload: every student in this file is already in {{ $review['class_label'] }}. No changes will be made.</p>
    @elseif($review['error_count'])
        <p class="font-semibold text-danger-600">Correct the listed IDs, names or other errors, then upload again. No students will be assigned until every row is valid.</p>
    @else
        <p class="text-success-700">Ready to confirm. @if(($review['target'] ?? 'class') === 'section') Only the listed students will be added to this section for this academic period. Select the saved section separately in Teaching Classes. @else Only the listed students will be added to this class. Their sections will be saved for this academic period. @endif Other section memberships and existing evaluations will be preserved.</p>
    @endif
    <div class="overflow-x-auto">
        <table class="w-full text-left"><thead><tr class="border-b"><th class="p-2">Row</th><th class="p-2">Section</th><th class="p-2">ID No.</th><th class="p-2">Student</th><th class="p-2">Validation</th></tr></thead><tbody>
            @foreach(array_slice($review['details'], 0, 100) as $row)
                <tr class="border-b border-gray-100"><td class="p-2">{{ $row['row'] }}</td><td class="p-2">{{ $row['section'] }}</td><td class="p-2">{{ $row['student_id'] }}</td>
                    <td class="p-2">{{ $row['last_name'] }}, {{ $row['first_name'] }} {{ $row['middle_name'] }}</td>
                    <td class="p-2 {{ $row['messages'] ? 'text-danger-600' : 'text-success-700' }}">{{ $row['messages'] ? implode(' ', $row['messages']) : 'Matched' }}@if($row['warnings'])<p class="text-gray-500">{{ implode(' ', $row['warnings']) }}</p>@endif</td></tr>
            @endforeach
        </tbody></table>
    </div>
    <p class="text-gray-500">Showing up to 100 rows. Use Download on the operation for the complete validation CSV. The masterlist identities are never overwritten by a section upload.</p>
</div>
