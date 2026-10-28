<div class="space-y-4">
    <p class="text-sm font-medium">{{ count($preview['rows'] ?? []) }} newly prepared classes found.</p>
    @if (empty($preview['rows']))
        <p class="text-sm text-gray-600">No new classes are ready. Classes need assigned students, confirmed sections when a subject has multiple instructors, and valid supervisor evaluators.</p>
    @endif
    @if (! empty($preview['excluded']))
        <details class="rounded-lg border border-gray-200 p-3 text-sm">
            <summary class="cursor-pointer font-medium">{{ count($preview['excluded']) }} classes still need attention</summary>
            <ul class="mt-3 space-y-2" style="max-height:12rem;overflow:auto">
                @foreach ($preview['excluded'] as $row)
                    <li><span class="font-medium">{{ $row['label'] }}</span> — {{ $row['reason'] }}</li>
                @endforeach
            </ul>
        </details>
    @endif
</div>
