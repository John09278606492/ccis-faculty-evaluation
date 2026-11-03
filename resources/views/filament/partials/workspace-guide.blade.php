@php
    $student = auth()->user()->isStudent();
    $links = [];
    if ($student && \App\Filament\Pages\MyEvaluations::canAccess()) {
        $links[] = ['title' => 'My faculty evaluations', 'text' => 'Complete open student evaluation assignments.', 'url' => \App\Filament\Pages\MyEvaluations::getUrl(), 'icon' => 'heroicon-o-clipboard-document-check'];
    }
    if ($student && \App\Filament\Pages\MyQuizzes::canAccess()) {
        $links[] = ['title' => 'My assessments', 'text' => 'Quizzes, exams, activities and released results.', 'url' => \App\Filament\Pages\MyQuizzes::getUrl(), 'icon' => 'heroicon-o-pencil-square'];
    }
    if ($student && \App\Filament\Pages\MyAcknowledgements::canAccess()) {
        $links[] = ['title' => 'My acknowledgements', 'text' => 'Sign receipts for documents from your instructors.', 'url' => \App\Filament\Pages\MyAcknowledgements::getUrl(), 'icon' => 'heroicon-o-document-check', 'badge' => \App\Filament\Pages\MyAcknowledgements::getNavigationBadge()];
    }
    if (! $student && \App\Filament\Pages\SupervisorEvaluations::canAccess()) {
        $links[] = ['title' => 'My Supervisor Tasks', 'text' => 'Answer Annex B for the faculty assigned to you.', 'url' => \App\Filament\Pages\SupervisorEvaluations::getUrl(), 'icon' => 'heroicon-o-clipboard-document-check'];
    }
    if (! $student && \App\Filament\Pages\MyEvaluationResults::canAccess()) {
        $links[] = ['title' => 'My Evaluation Results', 'text' => 'View released feedback for your own teaching.', 'url' => \App\Filament\Pages\MyEvaluationResults::getUrl(), 'icon' => 'heroicon-o-chart-bar'];
    }
@endphp
@if (! $student)
    @include('filament.partials.evaluation-workflow', ['expanded' => true])
@endif
@if ($student)
    @include('filament.partials.student-todo')
@endif
@if(count($links))
<x-filament::section>
    <x-slot name="heading">{{ $student ? 'My tasks' : 'My assigned work' }}</x-slot>
    <x-slot name="description">{{ $student ? 'Everything you can open from here.' : 'Your work follows your assigned campus, departments and permissions.' }}</x-slot>
    @if ($student)
        {{-- On a phone each task is one compact row with its count, instead of a tall card. --}}
        <style>
            .stu-tasks { display: grid; gap: 10px; }
            @media (min-width: 768px) { .stu-tasks { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
            .stu-task { display: flex; align-items: center; gap: 12px; min-height: 60px; padding: 12px 14px; border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; color: inherit; text-decoration: none; }
            .stu-task:hover { border-color: #818cf8; }
            .stu-task-text { flex: 1 1 auto; min-width: 0; }
            .stu-task-text strong { display: block; font-size: 15px; }
            .stu-task-text span { display: block; font-size: 13px; line-height: 1.35; color: #6b7280; }
            .stu-task-badge { flex: 0 0 auto; min-width: 24px; height: 24px; padding: 0 7px; box-sizing: border-box; display: grid; place-items: center; border-radius: 999px; background: #f59e0b; color: #fff; font-size: 13px; font-weight: 700; }
            .stu-task-chevron { flex: 0 0 auto; color: #9ca3af; font-size: 20px; line-height: 1; }
            .dark .stu-task { background: #111827; border-color: #374151; }
            .dark .stu-task-text span { color: #9ca3af; }
        </style>
        <div class="stu-tasks">
            @foreach($links as $link)
                <a href="{{ $link['url'] }}" wire:navigate class="stu-task">
                    <x-filament::icon :icon="$link['icon']" class="h-6 w-6 text-primary-600 dark:text-primary-400" />
                    <span class="stu-task-text"><strong>{{ $link['title'] }}</strong><span>{{ $link['text'] }}</span></span>
                    @if (! empty($link['badge']))<span class="stu-task-badge" aria-label="{{ $link['badge'] }} waiting">{{ $link['badge'] }}</span>@endif
                    <span class="stu-task-chevron" aria-hidden="true">&rsaquo;</span>
                </a>
            @endforeach
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-2">
            @foreach($links as $link)
                <a href="{{ $link['url'] }}" wire:navigate class="workspace-shortcut">
                    <x-filament::icon :icon="$link['icon']" class="h-6 w-6 text-primary-600 dark:text-primary-400" />
                    <span class="font-semibold">{{ $link['title'] }}</span><span class="text-sm text-gray-600 dark:text-gray-400">{{ $link['text'] }}</span>
                </a>
            @endforeach
        </div>
    @endif
</x-filament::section>
@endif
