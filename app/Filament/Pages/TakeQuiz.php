<?php

namespace App\Filament\Pages;

use App\Filament\Resources\QuizResource;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Notifications\BellNotification;
use App\Services\ClassroomUpdates;
use App\Services\FinalizeAssessmentAttempt;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class TakeQuiz extends Page
{
    use WithFileUploads;

    protected static ?string $slug = 'take-assessment/{quiz}';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.take-quiz';

    /**
     * Students take the quiz on its own distraction-free page — no admin
     * sidebar/navigation, same bare layout the login page uses.
     */
    protected static string $layout = 'filament-panels::components.layout.simple';

    protected function getLayoutData(): array
    {
        // Coding assessments get room for the workspace's Explorer and editor side by side.
        $hasCode = isset($this->quiz) && $this->quiz->questions->contains('type', 'code');

        return [
            'maxWidth' => $hasCode ? MaxWidth::SevenExtraLarge : MaxWidth::FourExtraLarge,
        ];
    }

    /**
     * The PHP runtime (about 18 MB) to download in the background before the
     * attempt starts, so the whole class is not fetching it when Run is first pressed.
     */
    public function phpRuntimeToPrefetch(): ?string
    {
        $runsPhp = $this->quiz->questions->contains(
            fn (QuizQuestion $question) => $question->type === 'code' && $question->codeRunner() === 'php' && $question->studentsCanRunCode()
        );
        $runtime = $runsPhp ? (glob(public_path('code-runner/*/php_*.wasm')) ?: [null])[0] : null;

        return $runtime ? asset(str_replace('\\', '/', substr($runtime, strlen(public_path()) + 1))) : null;
    }

    #[Locked]
    public Quiz $quiz;

    #[Locked]
    public ?QuizAttempt $attempt = null;

    #[Locked]
    public bool $reviewMode = false;

    /**
     * True when this student isn't allowed to take the quiz (not enrolled, or the
     * quiz isn't open). Shown as a friendly in-page message instead of a raw error.
     */
    public bool $accessDenied = false;

    public string $accessDeniedMessage = '';

    /**
     * True when an instructor/admin is previewing the quiz. Nothing is persisted:
     * the in-memory attempt/answers below are never saved to the database.
     */
    #[Locked]
    public bool $previewMode = false;

    /**
     * Preview mode's attempt is never saved, so it has no ID for Livewire to
     * re-fetch on the next request (unlike a real attempt). These plain scalar
     * values DO survive Livewire's request cycle, and boot() below rebuilds
     * $attempt from them on every request so it's never left null/stale.
     */
    public ?string $previewStartedAt = null;

    public ?string $previewExpiresAt = null;

    public int $previewMaxScore = 0;

    /**
     * True while the student is waiting for available_from to arrive. No attempt
     * exists yet — the exam simply hasn't opened. A live countdown (and a poll as
     * a safety net) transitions out of this automatically once the time comes.
     */
    public bool $waitingForStart = false;

    public bool $readyToStart = false;

    public ?string $availableFromIso = null;

    public function boot(): void
    {
        if ($this->previewMode) {
            $this->attempt = new QuizAttempt([
                'quiz_id' => $this->quiz->id,
                'attempt_number' => 0,
                'started_at' => $this->previewStartedAt,
                'expires_at' => $this->previewExpiresAt,
                'status' => 'in_progress',
                'max_score' => $this->previewMaxScore,
            ]);
        }
        // Once available_from arrives, transition out of the waiting room on the
        // very next request (the countdown's auto-reload, or the poll safety net).
        if (isset($this->quiz) && $this->waitingForStart && $this->quiz->hasWindowOpened()) {
            $this->waitingForStart = false;
            $this->resolveAttempt($this->quiz);
        }
    }

    /**
     * A no-op the waiting room polls periodically — boot() (above) does the actual
     * work of checking whether it's time to start, on every request regardless.
     */
    public function checkWaitingRoom(): void
    {
        $this->refreshLiveState();
    }

    #[On('classroom-push')]
    public function refreshLiveState(string $kind = 'reconnected', ?int $quizId = null, ?int $courseId = null): void
    {
        // Classmates starting and submitting send 'attempt' updates; they change nothing on an attempt in progress.
        $activeAttempt = $kind === 'attempt' && $this->attempt?->status === 'in_progress' && ! $this->reviewMode;
        if (in_array($kind, ['focus', 'progress'], true) || $activeAttempt || ($quizId && $quizId !== $this->quiz->id) || ($courseId && $courseId !== $this->quiz->course_offering_id)) {
            $this->skipRender();

            return;
        }
        if ($this->previewMode) {
            return;
        }
        abort_unless(auth()->user()?->isStudent() && auth()->user()->can('page_MyQuizzes'), 403);
        $this->quiz->refresh();
        $this->accessDenied = false;
        if (! $this->quiz->courseOffering->students()->where('users.id', auth()->id())->exists() || ! $this->quiz->isAssignedTo(auth()->id())) {
            $this->denyAccess('You are no longer assigned to this '.$this->kindNoun().'. Contact your instructor.');

            return;
        }
        $latest = $this->quiz->attempts()->where('user_id', auth()->id())->latest('attempt_number')->first();
        if ($latest) {
            $wasActive = $this->attempt?->id === $latest->id && ! $this->reviewMode;
            $this->attempt = $latest;
            $this->readyToStart = false;
            $this->waitingForStart = false;
            $this->reviewMode = $latest->status !== 'in_progress';
            // Do not reload answer inputs or pending uploads during an active attempt.
            if (! $wasActive || $this->reviewMode) {
                $this->loadAnswersIntoState();
            }
            $this->checkExpiry();

            return;
        }
        $this->readyToStart = false;
        $this->waitingForStart = false;
        $this->availableFromIso = null;
        $this->mountForStudent($this->quiz);
    }

    /** @var array<int, string|null> question_id => text/option-key answer */
    public array $answers = [];

    /** @var array<int, TemporaryUploadedFile|null> question_id => uploaded file */
    public array $files = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        // Students need the same page_MyQuizzes permission as the list page —
        // revoking it blocks direct quiz links too, not just the list view.
        return $user && (
            ($user->isStudent() && $user->can('page_MyQuizzes'))
            || static::canManage($user, request()->route('quiz'))
        );
    }

    /**
     * Previewing a quiz requires both the update_quiz permission (so revoking it
     * actually blocks access, same as everywhere else in the app) and ownership of
     * the course offering — super_admin bypasses both, matching QuizResource/QuizBuilder.
     */
    protected static function canManage($user, $quiz): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }
        $quiz = $quiz instanceof Quiz ? $quiz : Quiz::find($quiz);

        return $quiz
            && $user->can('update_quiz')
            && $quiz->courseOffering->user_id === $user->id;
    }

    public function mount(Quiz $quiz): void
    {
        $quiz->loadMissing(['courseOffering.course', 'courseOffering.instructor']);
        $this->quiz = $quiz;
        if (Auth::user()->isStudent()) {
            $this->mountForStudent($quiz);

            return;
        }
        abort_unless(static::canManage(Auth::user(), $quiz), 403, 'You do not manage this assessment.');
        $this->mountPreview($quiz);
    }

    protected function mountForStudent(Quiz $quiz): void
    {
        // Verify this student is actually registered for the course this quiz belongs to.
        if (! $quiz->courseOffering->students()->where('user_id', Auth::id())->exists()) {
            $this->denyAccess("You're signed in as {$this->studentDisplayName()}, but you're not registered for "
                ."\"{$quiz->courseOffering->display_name}\". If you think this is a mistake, contact your instructor "
                .'to be added to the class list.');

            return;
        }
        // Enrolled, but the instructor may have restricted this quiz to specific students.
        if (! $quiz->isAssignedTo(Auth::id())) {
            $this->denyAccess("You're signed in as {$this->studentDisplayName()}, but \"{$quiz->title}\" has not been "
                .'assigned to you. If you think this is a mistake, contact your instructor.');

            return;
        }
        $hasAnyAttempt = QuizAttempt::where('quiz_id', $quiz->id)
            ->where('user_id', Auth::id())
            ->exists();
        // Never attempted yet, and the window hasn't opened — wait for it, rather
        // than rejecting outright. The countdown transitions out of this on its own.
        if (! $hasAnyAttempt && $quiz->is_published && ! $quiz->hasWindowOpened()) {
            $this->waitingForStart = true;
            $this->availableFromIso = $quiz->available_from->toISOString();

            return;
        }
        $this->resolveAttempt($quiz);
    }

    /**
     * Decide whether to resume an in-progress attempt, start a fresh one, show a
     * past attempt for review, or deny access — called both from the initial
     * mount and when the waiting room's countdown reaches zero (see boot()).
     */
    protected function resolveAttempt(Quiz $quiz): void
    {
        $latestAttempt = QuizAttempt::where('quiz_id', $quiz->id)
            ->where('user_id', Auth::id())
            ->latest('attempt_number')
            ->first();
        if ($latestAttempt && $latestAttempt->status === 'in_progress') {
            $this->attempt = $latestAttempt;
            $this->checkExpiry();
        }
        if (! $this->attempt || $this->attempt->status !== 'in_progress') {
            if ($latestAttempt) {
                $this->attempt = $latestAttempt;
                $this->reviewMode = true;
            } elseif ($quiz->isAvailable() && $quiz->questions()->exists()) {
                $this->readyToStart = true;

                return;
            } else {
                $this->denyAccess('This '.$this->kindNoun().' is not ready to start. Check its opening time or contact your instructor.');

                return;
            }
        }
        $this->loadAnswersIntoState();
    }

    public function beginAttempt(): void
    {
        abort_if($this->previewMode, 403);
        $this->quiz->refresh();
        abort_unless(auth()->user()->isStudent()
            && auth()->user()->can('page_MyQuizzes')
            && $this->quiz->courseOffering->students()->where('users.id', auth()->id())->exists()
            && $this->quiz->isAssignedTo(auth()->id()), 403);
        if (! $this->quiz->isAvailable() || ! $this->quiz->questions()->exists()) {
            $this->readyToStart = false;
            $this->denyAccess('This '.$this->kindNoun().' is no longer available. Return to My Assessments for the latest schedule.');

            return;
        }
        $this->attempt = $this->startNewAttempt();
        $this->readyToStart = false;
        $this->reviewMode = false;
        $this->answers = [];
        $this->loadAnswersIntoState();
        $this->dispatch('close-modal', id: 'confirm-assessment-restart');
    }

    public function getTitle(): string
    {
        return isset($this->quiz) ? 'Take '.$this->quiz->kindLabel() : 'Take Assessment';
    }

    protected function kindNoun(): string
    {
        return strtolower($this->quiz->kindLabel());
    }

    protected function denyAccess(string $message): void
    {
        $this->accessDenied = true;
        $this->accessDeniedMessage = $message;
    }

    protected function studentDisplayName(): string
    {
        $user = Auth::user();

        return $user->student_id ? "{$user->full_name} ({$user->student_id})" : $user->full_name;
    }

    /**
     * Build the same page for an instructor/admin, but backed by an unsaved
     * attempt so nothing they click is ever written to the database.
     */
    protected function mountPreview(Quiz $quiz): void
    {
        $this->previewMode = true;
        $this->answers = $quiz->questions->mapWithKeys(fn ($question) => [$question->id => null])->all();
        $this->previewStartedAt = now()->toISOString();
        $this->previewExpiresAt = optional($this->computeExpiresAt($quiz, now()))->toISOString();
        $this->previewMaxScore = $quiz->questions()->sum('points');
        // boot() only rebuilds $attempt from the preview* scalars above on requests
        // *after* this one (mount() runs after boot(), which is too early to see
        // previewMode/quiz yet) — so build it here too for this first render.
        $this->attempt = new QuizAttempt([
            'quiz_id' => $quiz->id,
            'attempt_number' => 0,
            'started_at' => $this->previewStartedAt,
            'expires_at' => $this->previewExpiresAt,
            'status' => 'in_progress',
            'max_score' => $this->previewMaxScore,
        ]);
    }

    protected function startNewAttempt(): QuizAttempt
    {
        return DB::transaction(function (): QuizAttempt {
            $quiz = Quiz::query()->lockForUpdate()->findOrFail($this->quiz->id);
            $latestAttempt = QuizAttempt::query()
                ->where('quiz_id', $quiz->id)
                ->where('user_id', Auth::id())
                ->latest('attempt_number')
                ->first();
            if ($latestAttempt?->status === 'in_progress') {
                return $latestAttempt;
            }
            $attemptNumber = ($latestAttempt?->attempt_number ?? 0) + 1;
            abort_if(! $quiz->isAvailable() || $attemptNumber > $quiz->max_attempts, 409, 'No additional attempt is available.');
            $startedAt = now();
            $attempt = QuizAttempt::create([
                'quiz_id' => $quiz->id,
                'user_id' => Auth::id(),
                'attempt_number' => $attemptNumber,
                'started_at' => $startedAt,
                'expires_at' => $this->computeExpiresAt($quiz, $startedAt),
                'status' => 'in_progress',
                'max_score' => $quiz->questions()->sum('points'),
                'ip_address' => request()->ip(),
            ]);
            $attempt->answers()->createMany(
                $quiz->questions()->get()->map(fn ($question) => [
                    'quiz_question_id' => $question->id,
                ])->all(),
            );

            return $attempt;
        });
    }

    /**
     * Scheduled quizzes share one fixed cutoff for everyone (available_until),
     * regardless of when each student started. Duration quizzes give each student
     * their own duration_minutes counted from the moment they started.
     */
    protected function computeExpiresAt(Quiz $quiz, Carbon $startedAt): ?Carbon
    {
        if ($quiz->type === 'activity') {
            return $quiz->available_until;
        }
        if ($quiz->isScheduled()) {
            return $quiz->available_until;
        }

        return $quiz->duration_minutes ? $startedAt->copy()->addMinutes($quiz->duration_minutes) : null;
    }

    protected function loadAnswersIntoState(): void
    {
        $this->attempt->loadMissing('answers.question');
        foreach ($this->attempt->answers as $answer) {
            $this->answers[$answer->quiz_question_id] = $answer->answer_text;
        }
    }

    /**
     * Questions in display order, shuffled per-attempt (deterministically) when enabled.
     *
     * Collection::shuffle() in this Laravel version takes no seed — it's a
     * genuinely random shuffle every call. Since this computed property reruns
     * on every Livewire request (i.e. every time a student answers a question),
     * using it here silently re-shuffled the questions on every keystroke,
     * which looked like questions "swapping numbers" mid-attempt. Sorting by a
     * hash of (attempt id + question id) instead is fully deterministic: the
     * same attempt always produces the same order, with no reliance on any
     * global random-number-generator state.
     */
    #[Computed]
    public function questions()
    {
        $questions = $this->quiz->questions;
        if ($this->quiz->shuffle_questions && $this->attempt) {
            $attemptId = $this->attempt->id;
            $questions = $questions
                ->sortBy(fn ($question) => crc32("{$attemptId}-{$question->id}"))
                ->values();
        }

        return $questions;
    }

    /**
     * Lazily enforce the timer: if the attempt has expired, auto-finalize it immediately.
     * Called on mount and via wire:poll while the timer is running, so a deadline is
     * enforced even without a queue worker/cron process on the classroom LAN box.
     */
    public function checkExpiry(): void
    {
        if ($this->attempt && $this->attempt->status === 'in_progress' && $this->attempt->isExpired()) {
            $this->finalizeAttempt();
        }
    }

    public function updated(string $name, $value): void
    {
        // Preview answers only ever live in the $answers/$files arrays in memory — nothing to persist.
        if ($this->previewMode || $this->reviewMode || ! $this->attempt || $this->attempt->status !== 'in_progress') {
            return;
        }
        $this->checkExpiry();
        if ($this->attempt->status !== 'in_progress') {
            return;
        }
        if (str_starts_with($name, 'answers.')) {
            $questionId = (int) Str::after($name, 'answers.');
            $question = $this->quiz->questions()
                ->whereKey($questionId)
                ->where('type', '!=', 'file_upload')
                ->first();
            if (! $question || (! is_null($value) && ! is_scalar($value))) {
                return;
            }
            $answerValue = is_null($value) ? null : (string) $value;
            if ($answerValue !== null && $question->type === 'code') {
                if (($issue = $question->codeAnswerIssue($answerValue)) !== null) {
                    $this->addError($name, $issue);
                    // The editor's status bar would otherwise still say "Saved".
                    $this->dispatch('code-save-rejected', path: $name);

                    return;
                }
                $answerValue = $question->normalizeCodeAnswer($answerValue);
            } elseif ($answerValue !== null && mb_strlen($answerValue) > 10_000) {
                $this->addError($name, 'Answers may not exceed 10,000 characters.');

                return;
            }
            $answer = QuizAnswer::where('quiz_attempt_id', $this->attempt->id)
                ->where('quiz_question_id', $questionId);
            $wasAnswered = filled($answer->clone()->value('answer_text'));
            if (! $this->persistAnswer($question, ['answer_text' => $answerValue])) {
                $this->dispatch('code-save-rejected', path: $name);

                return;
            }
            // Autosaves while typing need no new page: re-render only when the
            // answered count changes, so a full classroom typing stays fast.
            if ($wasAnswered === filled($answerValue)) {
                $this->skipRender();
            } else {
                ClassroomUpdates::course($this->quiz->course_offering_id, 'progress', $this->quiz->id);
            }
        }
        if (str_starts_with($name, 'files.') && $value) {
            $questionId = (int) Str::after($name, 'files.');
            $question = $this->quiz->questions()
                ->whereKey($questionId)
                ->where('type', 'file_upload')
                ->first();
            if (! $question) {
                return;
            }
            $this->validateOnly($name, [
                $name => [
                    'required',
                    'file',
                    'max:10240',
                    'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,jpg,jpeg,png',
                ],
            ]);
            $answer = QuizAnswer::where('quiz_attempt_id', $this->attempt->id)
                ->where('quiz_question_id', $questionId)
                ->first();
            if (! $answer) {
                return;
            }
            $path = $value->store("quiz-submissions/{$this->quiz->id}", 'local');
            if (! $this->persistAnswer($question, ['answer_text' => $path])) {
                Storage::disk('local')->delete($path);

                return;
            }
            if ($answer->answer_text && str_starts_with($answer->answer_text, "quiz-submissions/{$this->quiz->id}/")) {
                Storage::disk('local')->delete($answer->answer_text);
            }
            $this->answers[$questionId] = $path;
            unset($this->files[$questionId]);
        }
    }

    /** The same row lock as finalization prevents a stale autosave writing after closure. */
    protected function persistAnswer(QuizQuestion $question, array $attributes): bool
    {
        return DB::transaction(function () use ($question, $attributes) {
            $attempt = QuizAttempt::query()->lockForUpdate()->findOrFail($this->attempt->id);
            abort_unless($attempt->user_id === auth()->id() && $attempt->quiz_id === $this->quiz->id, 403);
            if ($attempt->status !== 'in_progress' || $attempt->isExpired()) {
                return false;
            }

            return (bool) $attempt->answers()->where('quiz_question_id', $question->id)->update($attributes);
        });
    }

    public bool $confirmingSubmission = false;

    public function confirmSubmission(): void
    {
        if ($this->previewMode || $this->reviewMode || ! $this->attempt) {
            return;
        }
        $this->checkExpiry();
        $this->confirmingSubmission = $this->attempt->status === 'in_progress' && ! $this->getErrorBag()->isNotEmpty();
        if ($this->confirmingSubmission) {
            $this->dispatch('open-modal', id: 'confirm-assessment-submission');
        }
    }

    public function submit(): void
    {
        if ($this->previewMode) {
            Notification::make()
                ->title('This is only a preview')
                ->body('Nothing was submitted or saved — students will see exactly this page.')
                ->info()
                ->send();

            return;
        }
        $this->finalizeAttempt();
        $this->confirmingSubmission = false;
    }

    /**
     * Exams record when the student leaves the page during an attempt: a log and
     * an alert to the instructor, nothing more.
     */
    public function isSupervised(): bool
    {
        return ! $this->previewMode
            && ! $this->reviewMode
            && $this->quiz->type === 'exam'
            && $this->attempt?->status === 'in_progress'
            && $this->attempt->user_id === Auth::id();
    }

    public function recordFocusLost(string $kind = 'tab'): void
    {
        $this->skipRender();
        if (! $this->isSupervised() || $this->attempt->isExpired() || ! $this->attempt->recordFocusLost($kind)) {
            return;
        }
        ClassroomUpdates::quiz($this->quiz->id, 'focus');
        if (in_array($this->attempt->focus_leaves, QuizAttempt::FOCUS_ALERT_AT, true)) {
            $times = $this->attempt->focus_leaves === 1 ? 'once' : "{$this->attempt->focus_leaves} times";
            $this->alertInstructor(
                $kind === 'window' ? 'switched to another window' : 'left the exam page',
                "left {$times} so far.",
                'heroicon-o-eye-slash',
            );
        }
    }

    public function recordPaste(int $chars, int $lines = 1): void
    {
        $this->skipRender();
        if (! $this->isSupervised() || $this->attempt->isExpired() || $chars < QuizAttempt::PASTE_MIN_CHARS) {
            return;
        }
        $this->attempt->recordPaste(min($chars, 1_000_000), max(1, min($lines, 100_000)));
        ClassroomUpdates::quiz($this->quiz->id, 'focus');
        if (in_array($this->attempt->pastes, QuizAttempt::FOCUS_ALERT_AT, true)) {
            $this->alertInstructor(
                'pasted text into the exam',
                number_format($chars).' characters pasted from outside the exam ('.$this->attempt->pastes.' '.str('paste')->plural($this->attempt->pastes).' so far).',
                'heroicon-o-clipboard-document',
            );
        }
    }

    public function recordFocusReturned(): void
    {
        $this->skipRender();
        if ($this->attempt && $this->attempt->user_id === Auth::id() && $this->quiz->type === 'exam' && $this->attempt->recordFocusReturned()) {
            ClassroomUpdates::quiz($this->quiz->id, 'focus');
        }
    }

    protected function alertInstructor(string $what, string $detail, string $icon): void
    {
        $instructor = $this->quiz->courseOffering?->instructor;
        if (! $instructor) {
            return;
        }
        $student = Auth::user();

        BellNotification::make()
            ->title("{$student->name} {$what}")
            ->body(($student->student_id ? "{$student->student_id}, " : '')."{$this->quiz->title}: {$detail}")
            ->warning()
            ->icon($icon)
            ->actions([
                NotificationAction::make('view')
                    ->label('View attempts')
                    ->url(QuizResource::getUrl('edit', ['record' => $this->quiz], panel: 'admin', tenant: $this->quiz->campus)),
            ])
            ->sendToDatabase($instructor);
    }

    protected function finalizeAttempt(): void
    {
        if ($this->previewMode || ! $this->attempt || $this->attempt->status !== 'in_progress') {
            return;
        }
        app(FinalizeAssessmentAttempt::class)->handle($this->attempt);
        $this->reviewMode = true;
        $this->attempt->refresh();
        $this->confirmingSubmission = false;
        $this->dispatch('close-modal', id: 'confirm-assessment-submission');
    }
}
