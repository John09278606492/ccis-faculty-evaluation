{{-- The student's "do this now" list: open assessments first, then receipts waiting for a signature. --}}
@php($todo = app(\App\Services\StudentTodo::class)->for(auth()->user(), filament()->getTenant()?->id))
<style>
    .stu-todo { border: 1px solid #e5e7eb; border-radius: 16px; background: #fff; overflow: hidden; }
    .stu-todo-head { display: flex; align-items: center; gap: 12px; padding: 16px 18px; border-bottom: 1px solid #f1f5f9; }
    .stu-todo-count { flex: 0 0 auto; min-width: 40px; height: 40px; padding: 0 8px; box-sizing: border-box; display: grid; place-items: center; border-radius: 12px; background: #4f46e5; color: #fff; font-weight: 700; font-size: 18px; }
    .stu-todo-head h2 { margin: 0; font-size: 17px; font-weight: 700; line-height: 1.25; }
    .stu-todo-head p { margin: 2px 0 0; font-size: 13px; color: #6b7280; }
    .stu-todo-group { padding: 10px 18px 4px; font-size: 12px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #6b7280; }
    .stu-todo-item { display: flex; align-items: center; gap: 12px; min-height: 64px; padding: 10px 18px; border-top: 1px solid #f8fafc; text-decoration: none; color: inherit; }
    .stu-todo-item:hover { background: #f8fafc; }
    .stu-todo-icon { flex: 0 0 auto; width: 38px; height: 38px; display: grid; place-items: center; border-radius: 10px; }
    .stu-todo-icon svg { width: 20px; height: 20px; }
    .stu-todo-icon.is-exam { background: #eef2ff; color: #4338ca; }
    .stu-todo-icon.is-receipt { background: #fffbeb; color: #b45309; }
    .stu-todo-text { flex: 1 1 auto; min-width: 0; }
    .stu-todo-text strong { display: block; font-size: 15px; line-height: 1.3; overflow-wrap: anywhere; }
    .stu-todo-text span { display: block; margin-top: 2px; font-size: 13px; line-height: 1.35; color: #6b7280; overflow-wrap: anywhere; }
    .stu-todo-text .is-urgent { color: #b91c1c; font-weight: 600; }
    .stu-todo-btn { flex: 0 0 auto; display: inline-flex; align-items: center; min-height: 40px; padding: 0 16px; border-radius: 999px; background: #4f46e5; color: #fff; font-size: 14px; font-weight: 700; white-space: nowrap; }
    .stu-todo-btn.is-receipt { background: #d97706; }
    .stu-todo-btn.is-continue { background: #b45309; }
    .stu-todo-done { display: flex; align-items: center; gap: 12px; padding: 16px 18px; }
    .stu-todo-done .stu-todo-icon { background: #ecfdf5; color: #047857; }
    .dark .stu-todo { background: #111827; border-color: #374151; }
    .dark .stu-todo-head, .dark .stu-todo-item { border-color: #1f2937; }
    .dark .stu-todo-item:hover { background: #1f2937; }
    .dark .stu-todo-head p, .dark .stu-todo-group, .dark .stu-todo-text span { color: #9ca3af; }
    @media (max-width: 480px) {
        .stu-todo-head, .stu-todo-item, .stu-todo-done { padding-left: 14px; padding-right: 14px; }
        .stu-todo-group { padding-left: 14px; }
        .stu-todo-icon { width: 34px; height: 34px; }
        .stu-todo-btn { padding: 0 14px; }
    }
</style>
<section class="stu-todo" aria-labelledby="stu-todo-title">
    @if ($todo['total'] > 0)
        <div class="stu-todo-head">
            <span class="stu-todo-count">{{ $todo['total'] }}</span>
            <div>
                <h2 id="stu-todo-title">To do now</h2>
                <p>{{ collect([
                    $todo['assessments']->count() ? $todo['assessments']->count().' '.str('assessment')->plural($todo['assessments']->count()).' open' : null,
                    $todo['receipts']->sum('count') ? $todo['receipts']->sum('count').' '.str('receipt')->plural($todo['receipts']->sum('count')).' to sign' : null,
                    $todo['advising']->count() ? $todo['advising']->count().' advising tasks' : null,
                ])->filter()->join(' · ') }}</p>
            </div>
        </div>

        @if ($todo['assessments']->isNotEmpty())
            <div class="stu-todo-group">Assessments</div>
            @foreach ($todo['assessments'] as $item)
                <a class="stu-todo-item" href="{{ $item['url'] }}">
                    <span class="stu-todo-icon is-exam" aria-hidden="true"><x-heroicon-o-pencil-square /></span>
                    <span class="stu-todo-text">
                        <strong>{{ $item['title'] }}</strong>
                        <span>
                            {{ collect([$item['subject'], $item['type']])->filter()->join(' · ') }}
                            @if ($item['due'])
                                · <span @class(['is-urgent' => $item['due']->lt(now()->addDay())]) style="display:inline;margin:0;">Due {{ $item['due']->format('M j, g:ia') }}</span>
                            @endif
                        </span>
                    </span>
                    <span @class(['stu-todo-btn', 'is-continue' => $item['action'] === 'Continue'])>{{ $item['action'] }}</span>
                </a>
            @endforeach
        @endif

        @if ($todo['receipts']->isNotEmpty())
            <div class="stu-todo-group">Receipts to sign</div>
            @foreach ($todo['receipts'] as $item)
                <a class="stu-todo-item" href="{{ $item['url'] }}">
                    <span class="stu-todo-icon is-receipt" aria-hidden="true"><x-heroicon-o-document-check /></span>
                    <span class="stu-todo-text">
                        <strong>{{ $item['subject'] }}</strong>
                        <span>{{ $item['count'] }} {{ str('form')->plural($item['count']) }} waiting{{ $item['section'] ? ' · '.$item['section'] : '' }}</span>
                    </span>
                    <span class="stu-todo-btn is-receipt">{{ $item['count'] > 1 ? 'Sign all' : 'Sign' }}</span>
                </a>
            @endforeach
        @endif
        @if ($todo['advising']->isNotEmpty())
            <div class="stu-todo-group">Academic advising</div>
            @foreach ($todo['advising'] as $task)
                <a class="stu-todo-item" href="{{ \App\Filament\Pages\MyAdvising::getUrl() }}">
                    <span class="stu-todo-icon is-exam" aria-hidden="true"><x-heroicon-o-academic-cap /></span>
                    <span class="stu-todo-text"><strong>{{ $task->title }}</strong><span>{{ $task->advisoryClass->name }} · {{ $task->advising_term }}@if ($task->due_at) · Due {{ $task->due_at->timezone('Asia/Manila')->format('M j, g:ia') }}@endif</span></span>
                    <span>Submit files</span>
                </a>
            @endforeach
        @endif
    @else
        <div class="stu-todo-done">
            <span class="stu-todo-icon" aria-hidden="true"><x-heroicon-o-check-circle /></span>
            <div class="stu-todo-text">
                <strong id="stu-todo-title">You're all caught up</strong>
                <span>No open assessments, advising requirements or receipts to sign right now.</span>
            </div>
        </div>
    @endif
</section>
