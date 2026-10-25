<div class="space-y-4">
    <p><strong>{{ $task->title }}</strong> · {{ ucfirst($task->status) }}</p>
    <p class="text-sm text-gray-500">Reference: {{ $task->id }}</p>
    @if(isset($task->payload['source_name']))<p>File: {{ $task->payload['source_name'] }}</p>@endif
    @if($task->error)<p class="text-danger-600">{{ $task->error }}</p>@endif
    @if($task->result)<p>{{ $task->result['message'] ?? '' }}</p>@endif
    @if($task->kind === 'student-review' && isset($task->result['review']))
        @include('filament.forms.academic-student-import-review', ['review' => $task->result['review'], 'period' => \App\Services\AcademicPeriod::options(filament()->getTenant())[$task->result['review']['semester_id']] ?? ''])
    @elseif($task->kind === 'course-review' && isset($task->result['review']))
        @include('filament.forms.imported-course-review', ['review' => $task->result['review']])
    @elseif(in_array($task->kind, ['roster-review', 'section-review'], true) && isset($task->result['review']))
        @include('filament.forms.teaching-class-roster-review', ['review' => $task->result['review']])
    @endif
    @if(isset($task->result['confirmed_task_id']))<p class="text-success-700">The reviewed import has already been queued. See the import operation in Background Processing.</p>@endif
</div>
