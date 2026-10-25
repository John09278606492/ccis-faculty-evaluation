<div class="space-y-4 text-sm">
    <p><strong>Academic period:</strong> {{ $period }}</p>
    @foreach($review['sources'] ?? [] as $source)
        <p class="text-gray-600">{{ $source['format'] }} · {{ $source['sheet'] }} · headers on Excel row {{ $source['header_row'] }}@if(isset($source['campus'])) · {{ $source['campus'] }}@endif</p>
    @endforeach
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        @foreach(['new_students'=>'New students','updated_students'=>'Existing students','new_departments'=>'New departments','subject_enrolments'=>'Subject enrolments','error_count'=>'Rows with errors'] as $key=>$label)
            <div class="rounded-lg border border-gray-200 p-3"><p class="text-gray-500">{{ $label }}</p><p class="text-xl font-semibold">{{ number_format($review[$key] ?? 0) }}</p></div>
        @endforeach
    </div>
    <p>{{ number_format($review['total_rows']) }} selected rows. {{ number_format($review['duplicate_rows']) }} repeated-ID rows. {{ number_format($review['skipped_department_rows'] ?? 0) }} rows from unselected departments skipped.</p>
    @if($review['departments_to_create'] ?? [])
        <div>
            <p class="font-semibold">Colleges / departments to add in this campus</p>
            <table class="w-full text-left"><thead><tr class="border-b"><th class="py-2">Code</th><th class="py-2">Name</th></tr></thead><tbody>
                @foreach($review['departments_to_create'] as $department)<tr class="border-b border-gray-100"><td class="py-2">{{ $department['code'] }}</td><td>{{ $department['name'] }}</td></tr>@endforeach
            </tbody></table>
            <p class="mt-2 text-gray-500">If the file supplies only a college code, that code is used as its initial name. Edit the full name and assign its supervisor/chairperson under Departments afterward.</p>
        </div>
    @endif
    @if($review['cohorts'] ?? [])
        <table class="w-full text-left"><thead><tr class="border-b"><th class="py-2">College / degree program</th><th class="py-2 text-right">Students</th></tr></thead><tbody>
            @foreach($review['cohorts'] as $cohort=>$count)<tr class="border-b border-gray-100"><td class="py-2">{{ $cohort }}</td><td class="py-2 text-right">{{ number_format($count) }}</td></tr>@endforeach
        </tbody></table>
    @endif
    @if(($review['subject_enrolments'] ?? 0) > 0)
        @include('filament.forms.imported-course-review')
        <p>Program, reported year, units and subject enrolments will be saved for this period. Use <strong>Teaching Classes → Evaluators → Add from Master List Subjects</strong> to suggest students actually enrolled in that subject. Sections and instructors are not inferred from a campus report that does not contain them.</p>
        @if($review['ambiguous_subject_codes'] ?? 0)<p class="text-warning-700">{{ $review['ambiguous_subject_codes'] }} subject codes have more than one description. Choose the correct description when adding evaluators. Existing subject catalogues are preserved.</p>@endif
    @endif
    @if($review['without_subjects'] ?? 0)<p>{{ $review['without_subjects'] }} rows have no subject enrolments. Their student records can still be imported; no subject-based evaluator suggestions will be made for them.</p>@endif
    @if($review['error_count'])
        <p class="font-semibold text-danger-600">Fix the errors before importing. No students will be changed.</p>
        <ul class="list-disc space-y-1 pl-5">@foreach(array_slice($review['errors'],0,50) as $error)<li>@if($error['sheet'] ?? null){{ $error['sheet'] }}, @endif Row {{ $error['row'] }}: {{ implode(' ', $error['messages']) }}</li>@endforeach</ul>
        @if($review['error_count'] > 50)<p>Showing the first 50 error rows. Download the CSV for the complete list.</p>@endif
    @else
        <p class="text-success-700">Ready to import. Existing passwords, PINs, roles, evaluation responses and other academic periods are preserved. New students use ID-only evaluation entry; assessment login stays unavailable until a PIN is issued.</p>
    @endif
</div>
