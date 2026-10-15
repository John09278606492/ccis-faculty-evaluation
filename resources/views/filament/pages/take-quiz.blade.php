<x-filament-panels::page>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-500 dark:bg-gray-800/50 dark:text-gray-400">
        <span>
            Signed in as <strong>{{ auth()->user()->full_name }}</strong>
            @if (auth()->user()->student_id)
                ({{ auth()->user()->student_id }})
            @endif
            @if ($previewMode)
                — instructor/admin account, so you're seeing <strong>Preview Mode</strong> below, not the student sign-in.
                On a student's own device (not already signed in here), this same link shows the login page instead.
            @endif
        </span>
        <form method="POST" action="{{ filament()->getLogoutUrl() }}">
            @csrf
            <button type="submit" class="font-semibold text-primary-600 underline hover:text-primary-500 dark:text-primary-400">
                Not you? Log out
            </button>
        </form>
    </div>

    @if ($accessDenied)
        <div class="flex flex-col items-center gap-4 py-8 text-center">
            <div class="flex h-14 w-14 items-center justify-center rounded-full bg-danger-50 text-danger-600 dark:bg-danger-500/10 dark:text-danger-400">
                <x-heroicon-o-shield-exclamation class="h-8 w-8" />
            </div>
            <div>
                <h2 class="text-lg font-bold">You can't access this quiz</h2>
                <p class="mt-2 max-w-md text-sm text-gray-500">{{ $accessDeniedMessage }}</p>
            </div>
            <a href="{{ \App\Filament\Pages\MyQuizzes::getUrl(panel: 'admin', tenant: $quiz->campus) }}" class="fi-btn fi-btn-size-md fi-color-primary fi-btn-color-primary inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white">
                Back to My Quizzes
            </a>
        </div>
    @elseif ($waitingForStart)
        <div
            x-data="{
                startsAt: @js($availableFromIso),
                remaining: null,
                timer: null,
                init() {
                    this.tick();
                    this.timer = setInterval(() => this.tick(), 1000);
                },
                tick() {
                    let diff = Math.floor((new Date(this.startsAt) - new Date()) / 1000);
                    this.remaining = Math.max(diff, 0);
                    if (diff <= 0) {
                        clearInterval(this.timer);
                        $wire.refreshLiveState();
                    }
                },
                destroy() { clearInterval(this.timer); },
                formatted() {
                    if (this.remaining === null) return '';
                    let h = Math.floor(this.remaining / 3600).toString().padStart(2, '0');
                    let m = Math.floor((this.remaining % 3600) / 60).toString().padStart(2, '0');
                    let s = (this.remaining % 60).toString().padStart(2, '0');
                    return h + ':' + m + ':' + s;
                },
            }"
            wire:key="waiting-{{ $availableFromIso }}"
            class="flex flex-col items-center gap-4 py-8 text-center"
        >
            <div class="flex h-14 w-14 items-center justify-center rounded-full bg-warning-50 text-warning-600 dark:bg-warning-500/10 dark:text-warning-400">
                <x-heroicon-o-clock class="h-8 w-8" />
            </div>
            <div>
                <h2 class="text-lg font-bold">{{ $quiz->title }}</h2>
                <p class="text-sm text-gray-500">{{ $quiz->courseOffering->display_name }}</p>
                <p class="mt-3 text-sm">This {{ strtolower(\App\Models\Quiz::TYPES[$quiz->type] ?? 'quiz') }} hasn't started yet.
                    It opens at <strong>{{ $quiz->available_from->format('F j, Y g:i A') }}</strong>.</p>
            </div>
            <div class="rounded-lg bg-gray-100 px-4 py-2 dark:bg-gray-800">
                <span class="font-mono text-2xl font-bold" x-text="formatted()"></span>
                <p class="text-xs text-gray-500">until it starts</p>
            </div>
            <p class="text-xs text-gray-400">
                You don't have to stay on this page — leave and come back anytime before it starts,
                or check back once you get notified.
            </p>
            <a href="{{ \App\Filament\Pages\MyQuizzes::getUrl(panel: 'admin', tenant: $quiz->campus) }}" class="fi-btn fi-btn-size-md fi-color-primary fi-btn-color-primary inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white">
                Back to My Quizzes
            </a>
        </div>
    @elseif ($readyToStart)
        <x-filament::section>
            <x-slot name="heading">{{ $quiz->title }}</x-slot>
            <x-slot name="description">{{ $quiz->courseOffering->display_name }}</x-slot>
            <div class="space-y-4">
                <p class="whitespace-pre-line">{{ $quiz->instructions ?: 'Read each question carefully and submit when you are finished.' }}</p>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div><p class="text-sm text-gray-500">Questions</p><strong>{{ $quiz->questions()->count() }} · {{ $quiz->total_points }} points</strong></div>
                    <div><p class="text-sm text-gray-500">Time allowed</p><strong>{{ $quiz->type === 'activity' ? ($quiz->available_until ? 'Until the deadline' : 'Untimed') : ($quiz->isScheduled() ? 'Until the scheduled closing time' : $quiz->duration_minutes.' minutes from Start') }}</strong></div>
                    <div><p class="text-sm text-gray-500">Attempts allowed</p><strong>{{ $quiz->max_attempts }}</strong></div>
                </div>
                @if ($quiz->available_until)
                    <p class="text-sm">{{ $quiz->type === 'activity' || $quiz->isScheduled() ? 'Closes' : 'Last time to start' }}: <strong>{{ $quiz->available_until->format('M j, Y g:i A') }}</strong> ({{ config('app.timezone') }})</p>
                @endif
                <p class="text-sm text-gray-500">Your attempt begins only when you press Start. Once started, the timer keeps running even if you leave this page.</p>
                <div class="flex flex-wrap gap-3">
                    <x-filament::button wire:click="beginAttempt" wire:loading.attr="disabled">Start attempt</x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Pages\MyQuizzes::getUrl(panel: 'admin', tenant: $quiz->campus) }}">Back to My Quizzes</x-filament::button>
                </div>
            </div>
        </x-filament::section>
    @else
    @php
        $answeredCount = collect($this->questions)
            ->filter(fn ($question) => filled($answers[$question->id] ?? null))
            ->count();
        $totalCount = $this->questions->count();
        $progressPercent = $totalCount > 0 ? intval(($answeredCount / $totalCount) * 100) : 0;
    @endphp

    <div wire:key="quiz-attempt-{{ $attempt->id ?? 'preview' }}-{{ $attempt->status }}"
        x-data="{
            expiresAt: @js($reviewMode ? null : optional($attempt->expires_at)?->toIso8601String()),
            remaining: null,
            timer: null,
            init() {
                if (! this.expiresAt) return;
                this.tick();
                this.timer = setInterval(() => this.tick(), 1000);
            },
            tick() {
                let diff = Math.floor((new Date(this.expiresAt) - new Date()) / 1000);
                this.remaining = Math.max(diff, 0);
                if (diff <= 0) {
                    clearInterval(this.timer);
                    $wire.submit();
                }
            },
            formatted() {
                if (this.remaining === null) return '';
                let m = Math.floor(this.remaining / 60).toString().padStart(2, '0');
                let s = (this.remaining % 60).toString().padStart(2, '0');
                return m + ':' + s;
            },
        }"
    >
        @if ($previewMode)
            <div class="mb-6 flex items-start gap-3 rounded-lg bg-info-50 px-4 py-3 text-info-700 dark:bg-info-500/10 dark:text-info-400">
                <x-heroicon-o-eye class="mt-0.5 h-5 w-5 shrink-0" />
                <div class="text-sm">
                    <p class="font-semibold">Preview Mode</p>
                    <p>You're viewing this exactly as your students will see it. Nothing you do here is saved or submitted.</p>
                </div>
            </div>
        @endif

        <div class="mb-6">
            <h2 class="text-lg font-bold">{{ $quiz->title }}</h2>
            <p class="text-sm text-gray-500">{{ $quiz->courseOffering->display_name }}</p>

            @if ($quiz->instructions)
                <p class="mt-2 text-sm">{{ $quiz->instructions }}</p>
            @endif

            {{-- Exam header — the student's name, course, instructor, date, duration, and the
                 highest possible score, shown up front like the header of a printed exam. --}}
            <div class="mt-4 grid grid-cols-1 gap-x-6 gap-y-3 rounded-lg border border-gray-200 p-4 text-sm dark:border-gray-700 sm:grid-cols-3">
                <div>
                    <p class="text-xs text-gray-400">Student</p>
                    <p class="font-medium">
                        {{ auth()->user()->full_name }}
                        @if (auth()->user()->student_id)
                            ({{ auth()->user()->student_id }})
                        @endif
                    </p>
                </div>
                <div>
                    <p class="text-xs text-gray-400">Date</p>
                    <p class="font-medium">{{ $attempt->started_at->format('F j, Y') }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-400">Course / Subject</p>
                    <p class="font-medium">{{ $quiz->courseOffering->course?->name ?? $quiz->courseOffering->display_name }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-400">Instructor</p>
                    <p class="font-medium">{{ $quiz->courseOffering->instructor?->full_name ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-400">Duration</p>
                    <p class="font-medium">{{ $quiz->duration_minutes ? "{$quiz->duration_minutes} minutes" : 'Untimed' }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-400">Highest Possible Score</p>
                    <p class="font-medium">{{ $attempt->max_score }} {{ Str::plural('point', $attempt->max_score) }}</p>
                </div>
            </div>

            @if ($reviewMode)
                <div class="mt-3 rounded-lg bg-gray-100 px-3 py-2 text-sm dark:bg-gray-800">
                    Attempt #{{ $attempt->attempt_number }} of {{ $quiz->max_attempts }}
                    @if ($attempt->status === 'graded')
                        — <strong>Final score: {{ $attempt->score }} / {{ $attempt->max_score }}</strong>
                    @elseif ($attempt->status === 'submitted')
                        — <strong>Partial score: {{ $attempt->score }} / {{ $attempt->max_score }}</strong>
                        <span class="text-gray-500">(essay, code and file answers still need to be graded by your instructor — this isn't final yet)</span>
                    @endif
                </div>
            @endif
        </div>

        @if (!$reviewMode)
            <div class="quiz-progress-bar mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                @if ($attempt->status === 'in_progress' && $attempt->expires_at)
                    <div class="inline-flex items-center gap-2 rounded-lg bg-warning-50 px-3 py-1.5 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                        <x-heroicon-o-clock class="h-5 w-5" />
                        <span class="font-mono text-lg" x-text="formatted()"></span>
                    </div>
                @endif

                @if (!$reviewMode)
                    <div class="inline-flex items-center gap-2 text-sm text-gray-500">
                        <div class="h-2 w-32 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                            <div class="h-full rounded-full bg-primary-500" style="width: {{ $progressPercent }}%"></div>
                        </div>
                        <span>{{ $answeredCount }} of {{ $totalCount }} answered</span>
                    </div>
                @endif
                @if (!$reviewMode)
                    <details class="w-full text-sm">
                        <summary class="cursor-pointer text-primary-600">Jump to a question · {{ $totalCount - $answeredCount }} unanswered</summary>
                        <nav aria-label="Quiz questions" class="mt-2 flex flex-wrap gap-2">
                            @foreach ($this->questions as $number => $item)
                                <a href="#question-{{ $item->id }}" class="rounded border px-3 py-2 {{ filled($answers[$item->id] ?? null) ? 'bg-primary-50 text-primary-700' : 'text-gray-600 dark:text-gray-300' }}" aria-label="Question {{ $number + 1 }}, {{ filled($answers[$item->id] ?? null) ? 'answered' : 'unanswered' }}">{{ $number + 1 }}</a>
                            @endforeach
                        </nav>
                    </details>
                @endif
            </div>
        @endif

        <div class="space-y-4">
            @foreach ($this->questions as $index => $question)
                @php($savedAnswer = $reviewMode ? $attempt->answers->firstWhere('quiz_question_id', $question->id) : null)

                <div id="question-{{ $question->id }}" wire:key="question-{{ $question->id }}" class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                    <div class="mb-3 flex items-start gap-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                            {{ $index + 1 }}
                        </span>
                        <p class="font-medium">
                            {{ $question->question_text }}
                            <span class="ml-1 text-xs font-normal text-gray-400">
                                ({{ $question->points }} {{ Str::plural('point', $question->points) }})
                            </span>
                        </p>
                    </div>

                    <div class="pl-9">
                        @if ($question->type === 'multiple_choice')
                            <div class="space-y-1.5">
                                @foreach ($question->options as $option)
                                    <label class="flex items-center gap-2">
                                        <input
                                            type="radio"
                                            wire:model.live="answers.{{ $question->id }}"
                                            value="{{ $option['key'] }}"
                                            @disabled($reviewMode)
                                        />
                                        <span>{{ $option['label'] }}</span>
                                    </label>
                                @endforeach
                            </div>
                        @elseif ($question->type === 'true_false')
                            <div class="space-y-1.5">
                                <label class="flex items-center gap-2">
                                    <input type="radio" wire:model.live="answers.{{ $question->id }}" value="true" @disabled($reviewMode) />
                                    <span>True</span>
                                </label>
                                <label class="flex items-center gap-2">
                                    <input type="radio" wire:model.live="answers.{{ $question->id }}" value="false" @disabled($reviewMode) />
                                    <span>False</span>
                                </label>
                            </div>
                        @elseif ($question->type === 'short_answer')
                            <input
                                type="text"
                                wire:model.live.debounce.750ms="answers.{{ $question->id }}"
                                @disabled($reviewMode)
                                placeholder="Type your answer..."
                                class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900"
                            />
                        @elseif ($question->type === 'essay')
                            <textarea
                                wire:model.live.debounce.750ms="answers.{{ $question->id }}"
                                rows="4"
                                @disabled($reviewMode)
                                placeholder="Write your answer..."
                                class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900"
                            ></textarea>
                        @elseif ($question->type === 'code')
                            @if ($reviewMode && blank($answers[$question->id] ?? null))
                                <p class="text-sm text-gray-400">No code submitted.</p>
                            @else
                                <x-code-editor
                                    state-path="answers.{{ $question->id }}"
                                    :initial-value="$reviewMode ? '' : $question->starterCode()"
                                    :language="$question->codeLanguage()"
                                    :read-only="$reviewMode"
                                    :commit-debounce="$reviewMode ? null : 750"
                                    placeholder="Write your code here..."
                                    wire:key="code-{{ $question->id }}-{{ $reviewMode ? 'review' : 'edit' }}"
                                />
                            @endif
                        @elseif ($question->type === 'file_upload')
                            @if (!$reviewMode)
                                <input type="file" wire:model="files.{{ $question->id }}" />
                                <div wire:loading wire:target="files.{{ $question->id }}" class="mt-1 text-xs text-gray-400">
                                    Uploading...
                                </div>
                                @if ($answers[$question->id] ?? null)
                                    <p class="mt-1 text-xs text-success-600">A file has been uploaded.</p>
                                @endif
                            @elseif ($answers[$question->id] ?? null)
                                <a href="{{ route('quiz-submissions.download', $attempt->answers->firstWhere('quiz_question_id', $question->id)) }}" class="text-sm underline">
                                    View submitted file
                                </a>
                            @else
                                <p class="text-sm text-gray-400">No file submitted.</p>
                            @endif
                        @endif

                        @if ($reviewMode && $savedAnswer?->points_awarded !== null)
                            <p class="mt-2 text-xs {{ $savedAnswer->is_correct ? 'text-success-600' : 'text-danger-600' }}">
                                {{ $savedAnswer->points_awarded }} / {{ $question->points }} points
                                @if ($savedAnswer->feedback)
                                    — {{ $savedAnswer->feedback }}
                                @endif
                            </p>
                        @endif
                        @error('answers.'.$question->id)<p class="mt-2 text-sm text-danger-600">{{ $message }}</p>@enderror
                        @error('files.'.$question->id)<p class="mt-2 text-sm text-danger-600">{{ $message }}</p>@enderror
                    </div>
                </div>
            @endforeach
        </div>

        @if ($reviewMode)
            <div class="mt-6 flex flex-wrap gap-3">
                <x-filament::button tag="a" color="gray" href="{{ \App\Filament\Pages\MyQuizzes::getUrl(panel: 'admin', tenant: $quiz->campus) }}">Back to My Quizzes</x-filament::button>
                @if ($quiz->isAvailable() && $attempt->attempt_number < $quiz->max_attempts)
                    <x-filament::button wire:click="beginAttempt" wire:confirm="Start another attempt? The timer will begin immediately." wire:loading.attr="disabled">Start another attempt</x-filament::button>
                @endif
            </div>
        @endif
        @if (!$reviewMode)
            <p class="mt-4 text-sm text-gray-500" wire:loading role="status">Saving your changes…</p>
            <div class="mt-6 flex items-center gap-3">
                @if ($previewMode)
                    <x-filament::button wire:click="submit" color="gray">
                        Test Submit Button
                    </x-filament::button>
                    <span class="text-xs text-gray-400">(Preview only — this won't save anything)</span>
                @else
                    <x-filament::button
                        wire:click="submit"
                        wire:loading.attr="disabled"
                        wire:confirm="Submit this attempt? {{ $totalCount - $answeredCount }} question(s) remain unanswered. You cannot change answers after submitting."
                    >
                        Submit
                    </x-filament::button>
                @endif
            </div>
        @endif
    </div>
    @endif
    <style>
        .quiz-progress-bar { position: sticky; top: 4.5rem; z-index: 10; box-shadow: 0 2px 8px #00000008; }
        [id^="question-"] { scroll-margin-top: 13rem; }
    </style>
</x-filament-panels::page>
