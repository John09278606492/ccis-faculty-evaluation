<?php

namespace App\Services;

use App\Filament\Resources\QuizResource;
use App\Filament\Resources\QuizResource\RelationManagers\AttemptsRelationManager;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptEvent;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Exam supervision: records when a student leaves the exam page and returns, and
 * text pasted into an answer from outside the exam, while an attempt is in progress
 * on an assessment with supervise_attempts on. A browser cannot see which other
 * tab, site or app the student switched to, so nothing beyond this is recorded.
 *
 * resources/js/components/exam-supervision.js sends the events (through
 * TakeQuiz::superviseEvent); the thresholds below are mirrored there.
 */
class ExamSupervision
{
    /** Shorter absences (a stray click, a native dialog) are not recorded. */
    public const MIN_AWAY_SECONDS = 2;

    /** Outside pastes are recorded from this many characters or lines. */
    public const MIN_PASTE_CHARS = 100;

    public const MIN_PASTE_LINES = 3;

    /** How much pasted text is kept in the log. */
    public const MAX_PASTE_STORED = 10_000;

    /** A cap per attempt, so a misbehaving page cannot flood the database. */
    public const MAX_EVENTS = 500;

    /** At most one instructor notification per attempt in this many seconds. */
    public const NOTIFY_EVERY_SECONDS = 120;

    public static function isActive(QuizAttempt $attempt): bool
    {
        return $attempt->exists
            && $attempt->status === 'in_progress'
            && $attempt->quiz->supervise_attempts
            && $attempt->events()->count() < self::MAX_EVENTS;
    }

    /**
     * The student left the exam page $secondsAgo seconds ago (the browser waits
     * MIN_AWAY_SECONDS before reporting, so short absences are never sent).
     */
    public static function left(QuizAttempt $attempt, int $secondsAgo = 0, ?string $via = null): ?QuizAttemptEvent
    {
        if (! self::isActive($attempt)) {
            return null;
        }
        $open = self::openAbsence($attempt);
        if ($open) {
            return $open;
        }
        $event = $attempt->events()->create([
            'type' => QuizAttemptEvent::AWAY,
            'started_at' => now()->subSeconds(max(0, min($secondsAgo, 30))),
            'details' => $via === 'phpmyadmin' ? ['via' => 'phpmyadmin'] : null,
        ]);
        self::notify($attempt, 'left the '.self::kind($attempt).' page'.($via === 'phpmyadmin' ? ' (opened phpMyAdmin)' : '').'.');

        return $event;
    }

    /**
     * The student came back after $seconds away. Closes the open absence; if the
     * "left" report never arrived (connection lost), records the whole absence now.
     */
    public static function returned(QuizAttempt $attempt, int $seconds): ?QuizAttemptEvent
    {
        $open = self::openAbsence($attempt);
        if ($open) {
            // An attempt the timer submitted while the student was away ends at submission.
            $end = $attempt->status === 'in_progress' ? now() : ($attempt->submitted_at ?? now());
            $elapsed = (int) $open->started_at->diffInSeconds($end, true);
            // The browser measures more precisely; the server's own count bounds it.
            $open->update(['ended_at' => $end, 'seconds' => max(1, $elapsed, min($seconds, $elapsed + 5))]);
            self::changed($attempt);

            return $open;
        }
        if (! self::isActive($attempt) || $seconds < self::MIN_AWAY_SECONDS) {
            return null;
        }
        $seconds = min($seconds, max(1, (int) $attempt->started_at->diffInSeconds(now(), true)));
        $event = $attempt->events()->create([
            'type' => QuizAttemptEvent::AWAY,
            'started_at' => now()->subSeconds($seconds),
            'ended_at' => now(),
            'seconds' => $seconds,
        ]);
        self::notify($attempt, 'was away from the '.self::kind($attempt).' page for '.QuizAttemptEvent::formatSeconds($seconds).'.');

        return $event;
    }

    /**
     * Text pasted into an answer that was not copied inside the exam page and is
     * not already in the student's files. Small pastes are ignored.
     */
    public static function pastedFromOutside(QuizAttempt $attempt, ?int $questionId, string $text, ?string $file = null, int $chars = 0, int $lines = 0): ?QuizAttemptEvent
    {
        // The browser sends at most MAX_PASTE_STORED characters, with the full size.
        $text = mb_substr($text, 0, self::MAX_PASTE_STORED);
        $lines = max(substr_count(str_replace("\r\n", "\n", rtrim($text)), "\n") + 1, min($lines, 1_000_000));
        $chars = max(mb_strlen($text), min($chars, 100_000_000));
        if (! self::isActive($attempt) || ($chars < self::MIN_PASTE_CHARS && $lines < self::MIN_PASTE_LINES)) {
            return null;
        }
        if ($questionId !== null && ! $attempt->quiz->questions()->whereKey($questionId)->exists()) {
            $questionId = null;
        }
        $file = $file !== null && mb_strlen($file) <= 120 ? $file : null;
        $event = $attempt->events()->create([
            'type' => QuizAttemptEvent::PASTE,
            'quiz_question_id' => $questionId,
            'started_at' => now(),
            'details' => array_filter([
                'chars' => $chars,
                'lines' => $lines,
                'file' => $file,
                'text' => $text,
                'truncated' => $chars > self::MAX_PASTE_STORED,
            ], fn ($value) => $value !== null && $value !== false),
        ]);
        self::notify($attempt, 'pasted '.$lines.' '.Str::plural('line', $lines).' ('.number_format($chars).' characters) from outside the '.self::kind($attempt).'.');

        return $event;
    }

    /**
     * Counts for the Attempts table: ['away' => n, 'away_seconds' => n, 'pastes' => n].
     *
     * @return array{away: int, away_seconds: int, pastes: int}
     */
    public static function summary(QuizAttempt $attempt): array
    {
        $events = $attempt->relationLoaded('events') ? $attempt->events : $attempt->events()->get();
        $away = $events->where('type', QuizAttemptEvent::AWAY);

        return [
            'away' => $away->count(),
            'away_seconds' => (int) $away->sum(fn (QuizAttemptEvent $event) => $event->seconds
                ?? (int) $event->started_at->diffInSeconds($attempt->submitted_at ?? now(), true)),
            'pastes' => $events->where('type', QuizAttemptEvent::PASTE)->count(),
        ];
    }

    protected static function openAbsence(QuizAttempt $attempt): ?QuizAttemptEvent
    {
        return $attempt->events()->where('type', QuizAttemptEvent::AWAY)->whereNull('ended_at')->latest('id')->first();
    }

    protected static function kind(QuizAttempt $attempt): string
    {
        return strtolower($attempt->quiz->kindLabel());
    }

    /**
     * Tell the course instructor (bell notification), throttled per attempt, and
     * refresh any open Attempts table.
     */
    protected static function notify(QuizAttempt $attempt, string $what): void
    {
        self::changed($attempt);
        if (! Cache::add("exam-supervision:notified:{$attempt->id}", true, self::NOTIFY_EVERY_SECONDS)) {
            return;
        }
        $quiz = $attempt->quiz;
        $instructor = $quiz->courseOffering?->instructor;
        if (! $instructor) {
            return;
        }
        $student = $attempt->user;
        $name = $student->full_name.($student->student_id ? " ({$student->student_id})" : '');
        $tab = array_search(AttemptsRelationManager::class, QuizResource::getRelations(), true);
        Notification::make()
            ->title("{$name} {$what}")
            ->body("{$quiz->title} · {$quiz->courseOffering->display_name}. See the supervision log in Attempts & Submissions.")
            ->icon('heroicon-o-eye')
            ->iconColor('warning')
            ->actions([
                Action::make('view')
                    ->label('View attempts')
                    ->url(QuizResource::getUrl('edit', ['record' => $quiz, 'activeRelationManager' => $tab], panel: 'admin', tenant: $quiz->campus)),
            ])
            ->sendToDatabase($instructor);
    }

    protected static function changed(QuizAttempt $attempt): void
    {
        ClassroomUpdates::quiz($attempt->quiz_id, 'supervision');
    }
}
