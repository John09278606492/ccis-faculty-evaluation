<div class="space-y-3 text-sm">
    <dl class="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-1">
        <dt class="text-gray-500">To</dt><dd>{{ $reminder->student?->name }} @if($reminder->email)&lt;{{ $reminder->email }}&gt;@endif</dd>
        <dt class="text-gray-500">Sent by</dt><dd>{{ $reminder->sender?->name ?? 'Unknown' }}</dd>
        <dt class="text-gray-500">Evaluations left</dt><dd>{{ $reminder->remaining }}</dd>
        <dt class="text-gray-500">Status</dt><dd>{{ \App\Filament\Pages\EmailLog::STATUSES[$reminder->email_status] ?? $reminder->email_status }}@if($reminder->emailed_at) · {{ $reminder->emailed_at->format('M j, Y g:i A') }}@endif</dd>
    </dl>
    @if(filled($reminder->message))
        <p class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">{{ $reminder->message }}</p>
    @endif
    @if($reminder->email_status === 'failed' && $reminder->email_error)
        <p class="rounded-lg bg-danger-50 p-3 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">{{ $reminder->email_error }}</p>
    @endif
</div>
