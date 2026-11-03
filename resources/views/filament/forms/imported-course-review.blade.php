<div class="space-y-3 text-sm">
    <div class="grid grid-cols-3 gap-3">
        @foreach(['new_courses' => 'New courses to add', 'existing_courses' => 'Existing course matches', 'courses_needing_review' => 'Courses needing review'] as $key => $label)
            <div class="rounded-lg border border-gray-200 p-3"><p class="text-gray-500">{{ $label }}</p><p class="text-xl font-semibold">{{ number_format($review[$key] ?? 0) }}</p></div>
        @endforeach
    </div>
    <p>Missing, unambiguous subjects will be added under the student's college/department. Existing courses are kept unchanged. Equivalent codes ignore letter case and spaces. Degree programs are kept separately from course subjects.</p>
    @if($review['courses_to_create'] ?? [])
        <div style="max-height:240px;overflow:auto">
            <table class="w-full text-left"><thead><tr class="border-b"><th>Department</th><th>Code</th><th>New course title</th><th>Units</th></tr></thead><tbody>
            @foreach($review['courses_to_create'] as $course)<tr class="border-b border-gray-100"><td class="py-2">{{ $course['department'] }}</td><td>{{ $course['code'] }}</td><td>{{ $course['name'] }}</td><td>{{ $course['units'] }}</td></tr>@endforeach
            </tbody></table>
        </div>
    @endif
    @if($review['course_warnings'] ?? [])
        <p class="font-semibold text-warning-700">These courses will not be created or overwritten. Student records and original subject enrolments can still be imported.</p>
        <div style="max-height:240px;overflow:auto">
            <table class="w-full text-left"><thead><tr class="border-b"><th>Department / Code</th><th>Reported title(s)</th><th>Review needed</th></tr></thead><tbody>
            @foreach($review['course_warnings'] as $warning)<tr class="border-b border-gray-100"><td class="py-2">{{ $warning['department'] }} / {{ $warning['code'] }}</td><td>{{ $warning['titles'] }}</td><td>{{ $warning['reason'] }}</td></tr>@endforeach
            </tbody></table>
        </div>
    @endif
    <p>After assigning a real instructor and section in <strong>Teaching Classes</strong>, use <strong>Evaluators → Add from Master List Subjects</strong>. The subject enrolments identify candidates; review the class/section before adding them. This operation does not change existing evaluator rosters or results.</p>
</div>
