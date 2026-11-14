<div class="space-y-4 break-words">
    <p><strong>{{ $task->title }}</strong></p>
    <p><strong>Advising for:</strong> {{ $task->advising_term }}</p>
    @if ($task->due_at)<p>Due {{ $task->due_at->timezone('Asia/Manila')->format('F j, Y g:ia') }} (Philippine time)</p>@endif
    @if ($task->instructions)<p style="white-space:pre-wrap">{{ $task->instructions }}</p>@endif
    <ul class="list-disc pl-5 space-y-1">@foreach ($task->requirementList() as $requirement)<li>{{ $requirement }}</li>@endforeach</ul>
    <p><strong>Accepted files:</strong> {{ $task->fileTypesLabel() }} (up to 10 MB each)</p>
    @if ($task->consequence)<p class="font-semibold text-danger-600 dark:text-danger-400">{{ $task->consequence }}</p>@endif
    @if (! $task->acceptsSubmissions())<p>Submissions are closed. Contact your adviser if you need help.</p>@endif
    @include('filament.partials.advisory-files', ['submission' => $submission])
</div>
