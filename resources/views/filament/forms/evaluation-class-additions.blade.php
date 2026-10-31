<div class="space-y-4">
    <p class="text-sm font-medium">{{ count($preview['rows'] ?? []) }} newly prepared classes found.</p>
    @if (empty($preview['rows']))
        <p class="text-sm text-gray-600">No new classes are ready. Classes need assigned students, confirmed sections when a subject has multiple instructors, and valid supervisor evaluators.</p>
    @endif
    @php
        $attention = array_values(array_filter($preview['excluded'] ?? [], fn ($row) => empty($row['covered'])));
        $covered = array_values(array_filter($preview['excluded'] ?? [], fn ($row) => ! empty($row['covered'])));
    @endphp
    @if ($attention)
        <details class="rounded-lg border border-warning-300 p-3 text-sm" open>
            <summary class="cursor-pointer font-medium text-warning-700">{{ count($attention) }} classes need action</summary>
            <ul class="mt-3 space-y-2" style="max-height:12rem;overflow:auto">
                @foreach ($attention as $row)
                    <li><span class="font-medium">{{ $row['label'] }}</span> — {{ $row['reason'] }}</li>
                @endforeach
            </ul>
        </details>
    @endif
    @if ($covered)
        <details class="rounded-lg border border-gray-200 p-3 text-sm">
            <summary class="cursor-pointer font-medium text-gray-600">{{ count($covered) }} leftover copies need no action</summary>
            <p class="mt-2 text-gray-500">Their students already rate the same subject in this evaluation (the import groups each teacher's sections into one class). They stay out so nobody rates a subject twice.</p>
            <ul class="mt-3 space-y-2" style="max-height:12rem;overflow:auto">
                @foreach ($covered as $row)
                    <li><span class="font-medium">{{ $row['label'] }}</span> — {{ $row['reason'] }}</li>
                @endforeach
            </ul>
        </details>
    @endif
</div>
