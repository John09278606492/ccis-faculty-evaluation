<div class="space-y-4 text-sm" style="min-width:0;overflow-wrap:anywhere;">
    <p class="font-semibold">{{ $review['period'] }}</p>
    @foreach($review['sources'] ?? [] as $source)
        <p>{{ $source['sheet'] }}: headers on row {{ $source['header_row'] }}; {{ $source['ignored_columns'] }} unrelated columns ignored.</p>
    @endforeach
    @if($review['ignored_sheets'] ?? [])<p>Unrelated worksheets ignored: {{ implode(', ', $review['ignored_sheets']) }}.</p>@endif
    @if($review['repeated_headers'] ?? 0)<p>{{ $review['repeated_headers'] }} repeated heading rows ignored.</p>@endif
    @if($review['unclassified_period_count'] ?? 0)<p class="text-warning-700">{{ $review['unclassified_period_count'] }} rows excluded because their academic period could not be read. Download the review for the source rows and validation details.</p>@endif
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        @foreach(['new_classes' => 'New Classes', 'existing_classes' => 'Already Exist', 'new_faculty' => 'Faculty Profiles', 'blocked_classes' => 'Need Attention', 'new_courses' => 'New Subjects', 'new_sections' => 'New Sections', 'new_departments' => 'New Departments', 'empty_classes' => 'Awaiting Section Students'] as $key => $label)
            <div class="rounded-lg border border-gray-200 p-3"><p class="text-gray-500">{{ $label }}</p><p class="text-xl font-semibold">{{ number_format($review[$key]) }}</p></div>
        @endforeach
    </div>
    <p>{{ $review['selected_rows'] }} rows in this period. {{ $review['other_period_rows'] }} rows from other periods excluded; {{ $review['skipped_rows'] }} outside selected departments; {{ $review['duplicate_rows'] }} repeated class rows combined.</p>
    @if($review['blocked_classes'])
        <p class="text-warning-700">Only ready classes will be imported. Download the review for every issue. Correct the file or use optional matches when uploading again.</p>
    @endif
    @if($review['empty_classes'])
        <p>Empty sections will be created. Confirm their student uploads under Sections; imported draft class rosters can follow those uploads automatically. Customized lists and prepared evaluations are preserved.</p>
    @endif
    @if($review['new_faculty'])
        <details><summary class="cursor-pointer font-semibold">Faculty profiles to create ({{ $review['new_faculty'] }})</summary>
            <p class="my-2">The file has no email or home department. Complete those details in Staff Accounts and issue a temporary password when sign-in is needed. Source faculty IDs are stored separately from employee IDs.</p>
            <ul class="list-disc ps-5">@foreach($review['faculty_profiles'] as $faculty)<li>{{ $faculty['id'] }} — {{ $faculty['last_name'] }}, {{ $faculty['first_name'] }}</li>@endforeach</ul>
        </details>
    @endif
    <div class="overflow-x-auto" style="max-width:100%;max-height:55vh;overflow:auto;" tabindex="0" aria-label="Offering review rows"><table class="w-full text-left"><thead><tr><th class="p-2">Rows</th><th class="p-2">Subject / Faculty</th><th class="p-2">Department / Section</th><th class="p-2">Students</th><th class="p-2">Review</th></tr></thead><tbody>
        @foreach($review['details'] as $row)
            <tr class="border-t border-gray-200"><td class="p-2">{{ $row['rows'] }}</td><td class="p-2">{{ $row['subject'] }}<br><span class="text-gray-500">{{ $row['faculty'] }}</span></td><td class="p-2">{{ $row['department'] }}<br>{{ $row['section'] }}</td><td class="p-2">{{ $row['students'] }}</td><td class="p-2">{{ $row['status'] }}@foreach($row['messages'] as $message)<p class="text-warning-700">{{ $message }}</p>@endforeach</td></tr>
        @endforeach
    </tbody></table></div>
    <p class="text-gray-500">Preview shows up to 100 classes. The download includes every class. Existing classes, faculty home departments, supervisor choices and submitted evaluations stay unchanged.</p>
</div>
