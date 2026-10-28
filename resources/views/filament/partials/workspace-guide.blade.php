@php
    $student = auth()->user()->isStudent();
    $links = [];
    if ($student && \App\Filament\Pages\MyEvaluations::canAccess()) {
        $links[] = ['title' => 'My faculty evaluations', 'text' => 'Complete open student evaluation assignments.', 'url' => \App\Filament\Pages\MyEvaluations::getUrl(), 'icon' => 'heroicon-o-clipboard-document-check'];
    }
    if ($student && \App\Filament\Pages\MyQuizzes::canAccess()) {
        $links[] = ['title' => 'My assessments', 'text' => 'Quizzes, exams, activities and released results.', 'url' => \App\Filament\Pages\MyQuizzes::getUrl(), 'icon' => 'heroicon-o-pencil-square'];
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
@if(count($links))
<x-filament::section>
    <x-slot name="heading">{{ $student ? 'My tasks' : 'My assigned work' }}</x-slot>
    <div class="grid gap-4 md:grid-cols-2">
        @foreach($links as $link)
            <a href="{{ $link['url'] }}" wire:navigate class="workspace-shortcut">
                <x-filament::icon :icon="$link['icon']" class="h-6 w-6 text-primary-600 dark:text-primary-400" />
                <span class="font-semibold">{{ $link['title'] }}</span><span class="text-sm text-gray-600 dark:text-gray-400">{{ $link['text'] }}</span>
            </a>
        @endforeach
    </div>
</x-filament::section>
@endif
