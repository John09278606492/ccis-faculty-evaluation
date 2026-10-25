<div class="space-y-4">
    <p><strong>{{ $cycle->name }}</strong> · {{ $cycle->department->code }} · {{ $cycle->semester->name }}</p>
    <p>{{ $readiness['classes'] }} teaching classes · {{ $readiness['unique_students'] }} unique students · {{ $readiness['students'] }} student assignments · {{ $readiness['heads'] }} assigned supervisors</p>
    @foreach($cycle->assignments->whereNotNull('course_id') as $class)
        <p><strong>{{ $class->evaluatee?->name }} · {{ $class->course?->code }}:</strong> {{ $class->supervisorEvaluators->pluck('name')->join(', ') ?: 'No supervisor evaluators selected' }} (Annex B)</p>
    @endforeach
    @if($readiness['errors'])
        <p class="font-semibold text-danger-600">Complete the following before opening this evaluation:</p>
        <ul class="list-disc ps-6">@foreach($readiness['errors'] as $error)<li>{{ $error }}</li>@endforeach</ul>
    @else
        <p class="font-semibold text-success-600">Ready to open this evaluation.</p>
        <p>Confirming protects the form versions and prepares supervisor tasks. Students and supervisors may respond only during the selected opening and closing dates.</p>
    @endif
</div>
