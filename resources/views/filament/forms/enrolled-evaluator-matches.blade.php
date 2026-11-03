@php($chosen = collect($preview['rows'])->whereIn('id', $selected))
<div class="space-y-3 text-sm" style="min-width:0">
    <p>{{ count($preview['rows']) }} enrolled students found. {{ count($preview['automatic_ids']) }} clear matches. {{ $chosen->count() }} selected; {{ $chosen->whereNull('section')->count() }} with section pending.</p>
    <div style="max-height:280px;overflow:auto" tabindex="0" aria-label="Enrolled student matches">
        <table class="w-full text-left"><thead><tr><th class="p-2">Student</th><th class="p-2">Section</th><th class="p-2">Match</th></tr></thead><tbody>
        @forelse(array_slice($preview['rows'], 0, 50) as $row)
            <tr class="border-t border-gray-200"><td class="p-2">{{ $row['student_id'] }}<br>{{ $row['name'] }}</td><td class="p-2">{{ $row['section'] ?: 'Section pending' }}</td><td class="p-2">{{ $row['status'] }}</td></tr>
        @empty<tr><td colspan="3" class="p-2">No enrolled students match this subject and academic period.</td></tr>@endforelse
        </tbody></table>
    </div>
    @if(count($preview['rows']) > 50)<p>First 50 matches shown. Search Students to assign to find any matching student.</p>@endif
</div>
