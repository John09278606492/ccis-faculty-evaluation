<div class="space-y-4 text-sm">
    <p><strong>{{ $review['course'] }} · {{ $review['class_label'] }}</strong> · {{ $review['faculty'] }}</p>
    <p>Academic period: {{ $review['academic_period'] }}</p>
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        @foreach(['total_rows' => 'Student rows', 'matched_students' => 'Matched students', 'error_count' => 'Rows to correct', 'duplicate_rows' => 'Repeated rows'] as $key => $label)
            <div class="rounded-lg border border-gray-200 p-3"><p class="text-gray-500">{{ $label }}</p><p class="text-xl font-semibold">{{ number_format($review[$key]) }}</p></div>
        @endforeach
    </div>
    <p>Matched sections: @foreach($review['sections'] as $section => $count)<span class="mr-3">{{ $section }} ({{ $count }})</span>@endforeach</p>
    @if($review['error_count'])
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
