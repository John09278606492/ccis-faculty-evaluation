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

    @if (! $reviewMode && ! $accessDenied && ($runtime = $this->phpRuntimeToPrefetch()))
        <link rel="prefetch" href="{{ $runtime }}" as="fetch" crossorigin="anonymous">
    @endif

    @if ($accessDenied)
        <div class="flex flex-col items-center gap-4 py-8 text-center">
            <div class="flex h-14 w-14 items-center justify-center rounded-full bg-danger-50 text-danger-600 dark:bg-danger-500/10 dark:text-danger-400">
                <x-heroicon-o-shield-exclamation class="h-8 w-8" />
            </div>
            <div>
                <h2 class="text-lg font-bold">You can't access this {{ strtolower($quiz->kindLabel()) }}</h2>
                <p class="mt-2 max-w-md text-sm text-gray-500">{{ $accessDeniedMessage }}</p>
            </div>
            <a href="{{ \App\Filament\Pages\MyQuizzes::getUrl(panel: 'admin', tenant: $quiz->campus) }}" class="fi-btn fi-btn-size-md fi-color-primary fi-btn-color-primary inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white">
                Back to My Assessments
            </a>
        </div>
    @elseif ($waitingForStart)
        <div
            x-data="{
                startsAt: @js($availableFromIso),
                clockOffset: new Date(@js(now()->toIso8601String())) - Date.now(),
                remaining: null,
                timer: null,
                init() {
                    this.tick();
                    this.timer = setInterval(() => this.tick(), 1000);
                },
                tick() {
                    let diff = Math.floor((new Date(this.startsAt) - (Date.now() + this.clockOffset)) / 1000);
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
                Back to My Assessments
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
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Pages\MyQuizzes::getUrl(panel: 'admin', tenant: $quiz->campus) }}">Back to My Assessments</x-filament::button>
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
            // Count down on the server's clock: a student device set to the wrong time must not end the attempt.
            clockOffset: new Date(@js(now()->toIso8601String())) - Date.now(),
            remaining: null,
            timer: null,
            init() {
                if (! this.expiresAt) return;
                this.tick();
                this.timer = setInterval(() => this.tick(), 1000);
            },
            tick() {
                let diff = Math.floor((new Date(this.expiresAt) - (Date.now() + this.clockOffset)) / 1000);
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
                <p class="mt-2 whitespace-pre-line text-sm">{{ $quiz->instructions }}</p>
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
                    <p class="font-medium">{{ $quiz->timingLabel() }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-400">Highest Possible Score</p>
                    <p class="font-medium">{{ $attempt->max_score }} {{ Str::plural('point', $attempt->max_score) }}</p>
                </div>
            </div>

            @if ($reviewMode)
                <div class="mt-3 rounded-lg bg-gray-100 px-3 py-2 text-sm dark:bg-gray-800">
                    Attempt #{{ $attempt->attempt_number }} of {{ $quiz->max_attempts }}
                    @if (! $quiz->resultsVisibleToStudents())
                        — <strong>Submitted.</strong>
                        <span class="text-gray-500">Your instructor will release scores {{ $quiz->results_visibility === 'after_close' && $quiz->available_until ? 'after '.$quiz->available_until->format('M j, g:ia') : 'later' }}.</span>
                    @elseif ($attempt->status === 'graded')
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

                @if ($this->isSupervised())
                    {{-- Logs leaving this page during an exam and alerts the instructor. No screenshots, no lockdown. --}}
                    <div
                        wire:key="exam-supervision-{{ $attempt->id }}"
                        data-exam-supervision
                        x-data
                        x-init="$wire.recordFocusReturned()"
                        class="inline-flex items-center gap-1.5 text-xs text-gray-500"
                    >
                        <x-heroicon-o-eye class="h-4 w-4" />
                        <span>Leaving this page, switching windows and pasting from outside the exam are recorded, and your instructor is notified.</span>
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
                            <span class="whitespace-pre-line" style="font-feature-settings: 'calt' 0">{{ $question->question_text }}</span>
                            <span class="ml-1 text-xs font-normal text-gray-400">
                                ({{ $question->points }} {{ Str::plural('point', $question->points) }})
                            </span>
                        </p>
                    </div>

                    @if ($expectedOutput = $question->expectedOutputImages())
                        <div class="mb-4 pl-9">
                            <p class="mb-2 text-sm font-semibold">Expected output</p>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                @foreach ($expectedOutput as $image)
                                    <figure class="rounded-lg border border-gray-200 bg-white p-2 dark:border-gray-700">
                                        <img src="{{ $image['url'] }}" alt="{{ $image['caption'] }}" loading="lazy" class="mx-auto max-w-full" style="max-height: 22rem; object-fit: contain">
                                        @if ($image['caption'] !== '')
                                            <figcaption class="mt-2 text-xs text-gray-600">{{ $image['caption'] }}</figcaption>
                                        @endif
                                    </figure>
                                @endforeach
                            </div>
                        </div>
                    @endif

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
                                <p class="text-sm text-gray-400">No code files were changed.</p>
                            @else
                                <x-code-editor
                                    state-path="answers.{{ $question->id }}"
                                    :starter-files="$question->starterProject()"
                                    :read-only="$reviewMode"
                                    :can-manage-files="$question->studentsCanManageFiles()"
                                    :autocomplete="$question->allowsAutocomplete()"
                                    :can-run="$question->studentsCanRunCode()"
                                    :commit-debounce="$reviewMode ? null : 500"
                                    placeholder="Write your code here..."
                                    wire:key="code-{{ $question->id }}-{{ $reviewMode ? 'review' : 'edit' }}"
                                />
                            @endif
                            @if ($question->hasDatabase() && ! $reviewMode)
                                @php($databaseAnswer = $attempt->answers->firstWhere('quiz_question_id', $question->id))
                                <div class="mt-3 flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700">
                                    <x-heroicon-o-circle-stack class="h-5 w-5 shrink-0 text-primary-600" />
                                    <span class="flex-1">This question gives you your own MySQL database. Work on it in phpMyAdmin; it is saved with your answer when you submit.</span>
                                    @if ($previewMode || ! $databaseAnswer)
                                        <span class="text-xs text-gray-400">Each student gets their own database here.</span>
                                    @else
                                        <x-filament::button tag="a" size="sm" target="_blank" icon="heroicon-o-arrow-top-right-on-square"
                                            href="{{ route('student-databases.open', $databaseAnswer) }}"
                                            x-on:click.prevent="window.examPhpMyAdmin = window.open($el.href, 'ccis-phpmyadmin')">
                                            Open phpMyAdmin
                                        </x-filament::button>
                                    @endif
                                </div>
                            @elseif ($question->hasDatabase() && $savedAnswer)
                                <p class="mt-3 flex items-center gap-2 text-sm text-gray-500">
                                    <x-heroicon-o-circle-stack class="h-4 w-4 shrink-0" />
                                    {{ match (true) {
                                        $savedAnswer->hasVerifiedDatabaseSnapshot() => 'Your database was saved with this answer when you submitted.',
                                        $savedAnswer->database_snapshot_status === 'failed' => 'Your answer was submitted. The database snapshot needs recovery; your instructor has been notified and the workspace is retained.',
                                        $savedAnswer->database_snapshot_status === 'pending' => 'Your answer was submitted. The protected database snapshot is being prepared.',
                                        filled($savedAnswer->database_name) => 'Your database is kept for your instructor to review.',
                                        default => 'You did not open your database for this question.',
                                    } }}
                                </p>
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

                        @if ($reviewMode && $savedAnswer?->points_awarded !== null && $quiz->resultsVisibleToStudents())
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
                <x-filament::button tag="a" color="gray" href="{{ \App\Filament\Pages\MyQuizzes::getUrl(panel: 'admin', tenant: $quiz->campus) }}">Back to My Assessments</x-filament::button>
                @if ($quiz->isAvailable() && $attempt->attempt_number < $quiz->max_attempts)
                    <x-filament::button x-on:click="$dispatch('open-modal', { id: 'confirm-assessment-restart' })" wire:loading.attr="disabled">Start another attempt</x-filament::button>
                    <x-filament::modal id="confirm-assessment-restart" width="md">
                        <x-slot name="heading">Start another attempt?</x-slot>
                        <p>Your previous submission will be kept. {{ $quiz->type === 'activity' ? 'The new attempt follows the activity deadline.' : 'The timer starts when you continue.' }}</p>
                        <x-slot name="footerActions">
                            <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'confirm-assessment-restart' })">Cancel</x-filament::button>
                            <x-filament::button wire:click="beginAttempt" wire:loading.attr="disabled">Start new attempt</x-filament::button>
                        </x-slot>
                    </x-filament::modal>
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
                        wire:click="confirmSubmission"
                        wire:loading.attr="disabled"
                    >
                        Submit
                    </x-filament::button>
                @endif
            </div>
            @if (! $previewMode)
                {{-- Two steps: review every question, then read what submitting means and answer yes or no. --}}
                <x-filament::modal id="confirm-assessment-submission" width="2xl">
                    <div x-data="{ step: 'review' }"
                        x-on:open-modal.window="if ($event.detail.id === 'confirm-assessment-submission') step = 'review'">
                        <div x-show="step === 'review'">
                            <h2 class="text-lg font-semibold">Review your answers</h2>
                            <p class="mt-1 text-sm text-gray-500">{{ $answeredCount }} of {{ $totalCount }} questions answered. {{ $totalCount - $answeredCount }} unanswered.</p>
                            <ul style="max-height: 50vh" class="mt-4 divide-y divide-gray-100 overflow-y-auto rounded-lg border border-gray-200 dark:divide-gray-800 dark:border-gray-700" aria-label="Your answers">
                                @foreach ($this->questions as $number => $item)
                                    @php($isAnswered = filled($answers[$item->id] ?? null))
                                    <li class="flex items-center gap-3 px-3 py-2 text-sm">
                                        <span class="w-8 shrink-0 font-semibold text-gray-500">{{ $number + 1 }}.</span>
                                        <span class="min-w-0 flex-1 truncate">{{ Str::limit(preg_replace('/\s+/', ' ', $item->question_text), 90) }}</span>
                                        @if ($isAnswered)
                                            <span class="shrink-0 rounded-full bg-success-50 px-2 py-0.5 text-xs font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400">Answered</span>
                                        @else
                                            <span class="shrink-0 rounded-full bg-danger-50 px-2 py-0.5 text-xs font-medium text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">Not answered</span>
                                        @endif
                                        <a href="#question-{{ $item->id }}" class="shrink-0 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                                            x-on:click="$wire.set('confirmingSubmission', false); $dispatch('close-modal', { id: 'confirm-assessment-submission' })">Go to question</a>
                                    </li>
                                @endforeach
                            </ul>
                            <div class="mt-6 flex flex-wrap justify-end gap-3">
                                <x-filament::button color="gray" wire:click="$set('confirmingSubmission', false)" x-on:click="$dispatch('close-modal', { id: 'confirm-assessment-submission' })">Keep working</x-filament::button>
                                <x-filament::button x-on:click="step = 'confirm'">Continue to submit</x-filament::button>
                            </div>
                        </div>
                        <div x-show="step === 'confirm'" x-cloak>
                            <h2 class="text-lg font-semibold">Submit your final answers?</h2>
                            <div class="mt-3 space-y-2 rounded-lg border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-300">
                                <p><strong>This is final.</strong> After you click "Yes, submit", you can no longer open, edit, or add to any answer in this attempt.</p>
                                <p>Everything you saved so far{{ $this->questions->contains(fn ($item) => $item->type === 'code') ? ', including your code files'.($this->questions->contains(fn ($item) => $item->hasDatabase()) ? ' and your database' : '').',' : '' }} is sent to your instructor exactly as it is now.</p>
                                @if ($totalCount - $answeredCount > 0)
                                    <p><strong>{{ $totalCount - $answeredCount }} {{ Str::plural('question', $totalCount - $answeredCount) }} still unanswered</strong> and will get no points.</p>
                                @endif
                                <p>If you are not sure, click "No, go back" and keep working while there is time left.</p>
                            </div>
                            <div class="mt-6 flex flex-wrap justify-end gap-3">
                                <x-filament::button color="gray" x-on:click="step = 'review'">No, go back</x-filament::button>
                                <x-filament::button color="danger" wire:click="submit" wire:loading.attr="disabled">Yes, submit</x-filament::button>
                            </div>
                        </div>
                    </div>
                </x-filament::modal>
            @endif
        @endif
    </div>
    @endif
    <style>
        .quiz-progress-bar { position: sticky; top: 4.5rem; z-index: 10; box-shadow: 0 2px 8px #00000008; }
        [id^="question-"] { scroll-margin-top: 13rem; }
    </style>

{{-- Exam supervision: logs only, nothing is blocked. Active while the page shows the
     [data-exam-supervision] notice, i.e. during an exam attempt. The script must start
     with a declaration: Livewire evaluates it through Alpine, which wraps anything else. --}}
@script
<script>
    const active = () => document.querySelector('[data-exam-supervision]') !== null
    const squash = (text) => (text || '').replace(/\s+/g, ' ').trim()
    let away = false
    let watchingPhpMyAdmin = null
    let ignoreBlurUntil = 0
    let copiedHere = ''
    // Moving to another panel page keeps this document, so stop listening when the exam page goes.
    const page = new AbortController()
    const on = { signal: page.signal }
    document.addEventListener('livewire:navigating', () => {
        clearInterval(watchingPhpMyAdmin)
        page.abort()
    }, { once: true })

    const leave = (kind) => {
        if (away || ! active()) return
        away = true
        $wire.recordFocusLost(kind)
    }
    const back = () => {
        clearInterval(watchingPhpMyAdmin)
        watchingPhpMyAdmin = null
        if (! away) return
        away = false
        $wire.recordFocusReturned()
    }
    // The student's own phpMyAdmin tab (database questions) is part of the exam.
    const phpMyAdminVisible = () => {
        try {
            const tab = window.examPhpMyAdmin
            return !! tab && ! tab.closed && tab.location.pathname.startsWith('/phpmyadmin') && tab.document.visibilityState === 'visible'
        } catch {
            return false
        }
    }

    document.addEventListener('visibilitychange', () => {
        if (! document.hidden) return back()
        setTimeout(() => {
            if (! document.hidden) return
            if (! phpMyAdminVisible()) return leave('tab')
            // In phpMyAdmin: count it only once the student moves on to anything else.
            watchingPhpMyAdmin = setInterval(() => {
                if (! document.hidden) return back()
                if (! phpMyAdminVisible()) {
                    clearInterval(watchingPhpMyAdmin)
                    watchingPhpMyAdmin = null
                    leave('tab')
                }
            }, 1000)
        }, 400)
    }, on)

    // Another window side by side: the page stays visible but loses focus.
    window.addEventListener('blur', () => setTimeout(() => {
        if (document.hidden || document.hasFocus() || Date.now() < ignoreBlurUntil) return
        if (document.activeElement?.tagName === 'IFRAME') return // the code preview
        if (phpMyAdminVisible()) return
        leave('window')
    }, 250), on)
    window.addEventListener('focus', () => { if (! document.hidden) back() }, on)
    // File pickers and confirm dialogs take focus briefly; they are part of the exam.
    document.addEventListener('click', (event) => {
        if (event.target.closest?.('input[type=file], [wire\\:confirm]')) ignoreBlurUntil = Date.now() + 1500
    }, { capture: true, ...on })

    const selectionText = () => {
        const field = document.activeElement
        if (field && typeof field.selectionStart === 'number' && typeof field.value === 'string') {
            return field.value.slice(field.selectionStart, field.selectionEnd)
        }
        return window.getSelection()?.toString() ?? ''
    }
    document.addEventListener('copy', () => { copiedHere = squash(selectionText()) }, { capture: true, ...on })
    document.addEventListener('cut', () => { copiedHere = squash(selectionText()) }, { capture: true, ...on })
    document.addEventListener('paste', (event) => {
        if (! active()) return
        const text = event.clipboardData?.getData('text') ?? ''
        const squashed = squash(text)
        // Moving code around inside the exam is fine; only text from elsewhere is logged.
        if (squashed.length < 40 || squashed === copiedHere) return
        $wire.recordPaste(text.length, text.split('\n').length)
    }, { capture: true, ...on })
</script>
@endscript
</x-filament-panels::page>
