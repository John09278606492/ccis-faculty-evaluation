@php($academic = $student->academicRecords->firstWhere('semester_id', $period))
@php($subjects = $student->subjectEnrolments()->where('semester_id', $period)->orderBy('subject_code')->get())
<div class="space-y-4 text-sm">
    <p class="font-semibold">{{ $student->student_id }} — {{ $student->name }}</p>
    @if($academic)
        <p>{{ $academic->program }}</p>
        @if($academic->reported_year_level)
            <p>Reported year {{ $academic->reported_year_level }}</p>
        @endif
        @if($academic->total_units !== null)
            <p>{{ $academic->total_units }} total units</p>
        @endif
        @if($academic->reported_sex)
            <p>Sex as reported: {{ $academic->reported_sex }}</p>
        @endif
    @endif
    <p class="text-gray-500">Imported academic information applies to this period. Subjects do not identify an instructor or class section.</p>
    <div class="overflow-x-auto"><table class="w-full text-left"><thead><tr class="border-b"><th class="p-2">Subject</th><th class="p-2">Description</th><th class="p-2">Lec</th><th class="p-2">Lab</th><th class="p-2">Units</th></tr></thead><tbody>
        @forelse($subjects as $subject)
            <tr class="border-b border-gray-100"><td class="p-2">{{ $subject->subject_code }}</td><td class="p-2">{{ $subject->description }}</td><td class="p-2">{{ $subject->lecture }}</td><td class="p-2">{{ $subject->laboratory }}</td><td class="p-2">{{ $subject->units }}</td></tr>
        @empty
            <tr><td colspan="5" class="p-3">No subject enrolments imported for this period.</td></tr>
        @endforelse
    </tbody></table></div>
</div>
