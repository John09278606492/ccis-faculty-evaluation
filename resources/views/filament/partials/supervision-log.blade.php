@php
    use App\Models\QuizAttemptEvent;

    $kind = strtolower($attempt->quiz->kindLabel());
    $numbers = $attempt->quiz->questions->pluck('id')->flip();
    $events = $attempt->events->sortBy([['started_at', 'asc'], ['id', 'asc']]);
@endphp
<div class="space-y-3 text-sm">
    <p class="text-gray-500 dark:text-gray-400">
        <strong class="text-gray-700 dark:text-gray-200">{{ $summary }}.</strong>
        "Left the page" means the student switched to another tab, window or app; browsers do not reveal which one.
        Pastes are listed only when the text was not copied inside the {{ $kind }} and was not already in the student's files
        ({{ \App\Services\ExamSupervision::MIN_PASTE_LINES }}+ lines or {{ \App\Services\ExamSupervision::MIN_PASTE_CHARS }}+ characters).
        This is evidence for your judgement, not proof of cheating.
    </p>

    @if ($events->isEmpty())
        <p class="text-gray-400">Nothing was recorded for this attempt.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
            <table class="w-full text-left">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2">Time</th>
                        <th class="px-3 py-2">What</th>
                        <th class="px-3 py-2">Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($events as $event)
                        <tr class="align-top">
                            <td class="whitespace-nowrap px-3 py-2 font-mono text-xs">{{ $event->started_at->format('g:i:s A') }}</td>
                            @if ($event->type === QuizAttemptEvent::AWAY)
                                <td class="whitespace-nowrap px-3 py-2">
                                    Left the page
                                    @if (($event->details['via'] ?? null) === 'phpmyadmin')
                                        <span class="block text-xs text-gray-400">after opening phpMyAdmin</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    @if ($event->ended_at)
                                        Away {{ QuizAttemptEvent::formatSeconds($event->seconds ?? 0) }}, back at {{ $event->ended_at->format('g:i:s A') }}
                                    @elseif ($attempt->status === 'in_progress')
                                        <span class="text-warning-600">Has not come back yet</span>
                                    @else
                                        <span class="text-warning-600">Did not come back before the attempt ended</span>
                                    @endif
                                </td>
                            @else
                                <td class="whitespace-nowrap px-3 py-2">
                                    Pasted from outside
                                    @if ($event->quiz_question_id && $numbers->has($event->quiz_question_id))
                                        <span class="block text-xs text-gray-400">
                                            Question {{ $numbers[$event->quiz_question_id] + 1 }}@if ($event->details['file'] ?? null), {{ $event->details['file'] }}@endif
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    <details>
                                        <summary class="cursor-pointer">
                                            {{ number_format($event->details['lines'] ?? 0) }} {{ Str::plural('line', $event->details['lines'] ?? 0) }},
                                            {{ number_format($event->details['chars'] ?? 0) }} characters
                                            @if ($event->details['truncated'] ?? false)
                                                (first {{ number_format(\App\Services\ExamSupervision::MAX_PASTE_STORED) }} kept)
                                            @endif
                                        </summary>
                                        <pre class="mt-2 max-h-64 overflow-auto whitespace-pre-wrap rounded bg-gray-50 p-2 font-mono text-xs dark:bg-gray-800">{{ $event->details['text'] ?? '' }}</pre>
                                    </details>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
