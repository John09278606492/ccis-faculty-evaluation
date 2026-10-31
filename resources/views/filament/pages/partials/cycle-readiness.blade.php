<div class="space-y-4">
    <p><strong>{{ $cycle->name }}</strong> · {{ $cycle->department->code }} · {{ $cycle->semester->name }}</p>
    <p>{{ $readiness['classes'] }} teaching classes · {{ $readiness['unique_students'] }} unique students · {{ $readiness['students'] }} student assignments · {{ $readiness['heads'] }} assigned supervisors</p>
    <p>Opens: {{ $cycle->starts_at?->format('M j, Y g:i A') ?? 'When you open the evaluation' }} · Closes: {{ $cycle->ends_at?->format('M j, Y g:i A') ?? 'When you select Close Evaluation' }}</p>
    @if($cycle->department->supervisor_id)
        <p>Use <strong>Use Department Supervisor</strong> below to fill missing Annex B supervisor choices in bulk. Individual class selections are preserved.</p>
    @endif
    <details><summary class="cursor-pointer font-semibold">View supervisor assignments by teaching class</summary>
    @foreach($cycle->assignments->whereNotNull('course_id') as $class)
        <p><strong>{{ $class->evaluatee?->name }} · {{ $class->course?->code }}:</strong> {{ $class->supervisorEvaluators->pluck('name')->join(', ') ?: 'No supervisor evaluators selected' }} (Annex B)</p>
    @endforeach
    </details>
    @if($readiness['errors'])
        <p class="font-semibold text-danger-600">Complete the following before opening this evaluation:</p>
        <div style="max-height:18rem;overflow:auto"><ul class="list-disc ps-6">@foreach($readiness['errors'] as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @else
        <p class="font-semibold text-success-600">Ready to open this evaluation.</p>
        <p>Confirming protects the form versions and prepares supervisor tasks. Any dates you set are enforced. With no closing date, responses remain open until you select Close Evaluation.</p>
    @endif
</div>
