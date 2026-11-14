<div class="space-y-3 break-words">
    @if ($submission)
        @if ($submission->note)<p><strong>Student note:</strong> {{ $submission->note }}</p>@endif
        @if ($submission->feedback)<p><strong>Adviser feedback:</strong> {{ $submission->feedback }}</p>@endif
        @forelse ($submission->files ?? [] as $index => $path)
            <div><x-filament::link :href="route('advising.file', ['submission' => $submission->id, 'index' => $index])" target="_blank" rel="noopener">View file {{ $index + 1 }} ({{ strtoupper(pathinfo($path, PATHINFO_EXTENSION)) }})</x-filament::link></div>
        @empty
            <p>No files submitted yet.</p>
        @endforelse
    @endif
</div>
