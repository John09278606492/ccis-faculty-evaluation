<?php

namespace App\Models;

use App\Filament\Pages\TakeQuiz;
use App\Services\AutoSyncClassRecord;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Quiz extends Model
{
    use HasFactory;

    protected $attributes = ['auto_sync_class_record' => true];

    protected static function boot(): void
    {
        parent::boot();
        static::saved(function (self $quiz) {
            if ($quiz->auto_sync_class_record && $quiz->wasChanged(['auto_sync_class_record', 'available_from', 'title', 'course_offering_id', 'type'])) {
                AutoSyncClassRecord::afterCommit($quiz->id);
            }
        });
        // If the instructor reschedules the start time, let the alert fire again.
        static::saving(function (self $quiz) {
            if ($quiz->is_published && $quiz->isDirty('is_published')) {
                $issues = $quiz->publicationIssues();
                if ($issues) {
                    throw ValidationException::withMessages(['data.is_published' => implode(' ', $issues)]);
                }
            }
            if ($quiz->isDirty('available_from') && ! $quiz->isDirty('notified_at')) {
                $quiz->notified_at = null;
            }
        });
    }

    public function publicationIssues(): array
    {
        $questions = $this->questions()->get();
        $issues = [];
        if ($questions->isEmpty()) {
            $issues[] = 'Add questions in the question builder before publishing.';
        }
        foreach ($questions as $index => $question) {
            foreach ($question->validationIssues() as $issue) {
                $issues[] = 'Question '.($index + 1).': '.$issue;
            }
        }
        if ($this->passing_score !== null && $this->passing_score > $questions->sum('points')) {
            $issues[] = 'The passing score cannot exceed the total points.';
        }
        if ($this->available_from && $this->available_until && $this->available_until->lte($this->available_from)) {
            $issues[] = 'The closing time must be after the opening time.';
        }

        return $issues;
    }

    protected $fillable = [
        'auto_sync_class_record',
        'course_offering_id',
        'title',
        'instructions',
        'type',
        'timing_mode',
        'duration_minutes',
        'available_from',
        'available_until',
        'notify_minutes_before',
        'notified_at',
        'shuffle_questions',
        'max_attempts',
        'passing_score',
        'results_visibility',
        'results_released_at',
        'is_published',
        'assign_to_all',
    ];

    protected $casts = [
        'auto_sync_class_record' => 'boolean',
        'class_record_synced_at' => 'datetime',
        'duration_minutes' => 'integer',
        'available_from' => 'datetime',
        'available_until' => 'datetime',
        'results_released_at' => 'datetime',
        'notify_minutes_before' => 'integer',
        'notified_at' => 'datetime',
        'shuffle_questions' => 'boolean',
        'passing_score' => 'integer',
        'max_attempts' => 'integer',
        'is_published' => 'boolean',
        'assign_to_all' => 'boolean',
    ];

    /**
     * Available quiz types.
     */
    /**
     * When students see their scores. Holding them back stops early finishers from
     * passing answers to classmates who are still taking the assessment.
     */
    public const RESULTS_VISIBILITY = [
        'immediately' => 'Right after submitting',
        'after_close' => 'After the assessment closes',
        'manual' => 'When I release them',
    ];

    public function resultsVisibleToStudents(): bool
    {
        if ($this->results_released_at) {
            return true;
        }

        return match ($this->results_visibility ?? 'immediately') {
            'after_close' => $this->hasWindowClosed(),
            'manual' => false,
            default => true,
        };
    }

    public function holdsResults(): bool
    {
        return ($this->results_visibility ?? 'immediately') !== 'immediately';
    }

    public const TYPES = [
        'quiz' => 'Quiz',
        'exam' => 'Exam',
        'activity' => 'Activity (untimed, deadline-based)',
    ];

    /**
     * What students and instructors call this assessment: Quiz, Exam or Activity.
     */
    public function kindLabel(): string
    {
        return ['quiz' => 'Quiz', 'exam' => 'Exam', 'activity' => 'Activity'][$this->type] ?? 'Assessment';
    }

    /**
     * How the timer works.
     */
    public const TIMING_MODES = [
        'duration' => 'Duration — student clicks Start, gets a fixed amount of time from that moment',
        'scheduled' => 'Scheduled — opens and closes at fixed clock times, the same for everyone',
    ];

    /**
     * How far ahead of available_from students can be alerted.
     */
    public const NOTIFY_OPTIONS = [
        '' => "Don't notify",
        '2' => '2 minutes before',
        '5' => '5 minutes before',
        '10' => '10 minutes before',
        '15' => '15 minutes before',
        '30' => '30 minutes before',
        '60' => '1 hour before',
        '1440' => '1 day before',
    ];

    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('order');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    /**
     * The full class roster (every student enrolled in this quiz's course
     * offering), regardless of whether they're individually assigned this quiz.
     * Joins through course_offering_student via course_offering_id, not quiz.id.
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'course_offering_student',
            'course_offering_id',
            'user_id',
            'course_offering_id',
        )->using(ClassroomEnrollment::class);
    }

    /**
     * Students explicitly assigned this quiz. Only consulted when assign_to_all
     * is false — otherwise every enrolled student (see students()) is eligible.
     */
    public function assignedStudents(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'quiz_student')->using(QuizAssignment::class);
    }

    /**
     * Whether the given student is allowed to take this quiz: everyone enrolled
     * when assign_to_all is on, otherwise only students in the assignedStudents pivot.
     */
    public function isAssignedTo(int $userId): bool
    {
        if ($this->assign_to_all) {
            return true;
        }

        return $this->relationLoaded('assignedStudents')
            ? $this->assignedStudents->contains('id', $userId)
            : $this->assignedStudents()->where('users.id', $userId)->exists();
    }

    /**
     * Get the campus this quiz belongs to, via the course offering chain.
     */
    public function getCampusAttribute()
    {
        return $this->courseOffering?->campus;
    }

    /**
     * Total points available across all questions.
     */
    public function getTotalPointsAttribute(): int
    {
        return $this->questions()->sum('points');
    }

    /**
     * Whether a given score meets this quiz's passing_score threshold.
     * Null when no threshold is set — callers should treat that as "no verdict".
     */
    public function hasPassed(?float $score): ?bool
    {
        if ($this->passing_score === null || $score === null) {
            return null;
        }

        return $score >= $this->passing_score;
    }

    /**
     * Whether the quiz is currently within its availability window and published.
     */
    public function isAvailable(): bool
    {
        return $this->is_published && $this->hasWindowOpened() && ! $this->hasWindowClosed();
    }

    /**
     * Whether available_from has arrived yet (true if there's no start time at all).
     */
    public function hasWindowOpened(): bool
    {
        return ! $this->available_from || now()->gte($this->available_from);
    }

    /**
     * Whether available_until has already passed.
     */
    public function hasWindowClosed(): bool
    {
        return (bool) ($this->available_until && now()->gte($this->available_until));
    }

    public function isScheduled(): bool
    {
        return $this->timing_mode === 'scheduled';
    }

    /**
     * Whether it's time to send the "starting soon" alert (and it hasn't gone out yet).
     */
    public function isDueForStartingSoonNotification(): bool
    {
        if (! $this->is_published || ! $this->available_from || ! $this->notify_minutes_before || $this->notified_at) {
            return false;
        }

        return now()->gte($this->available_from->copy()->subMinutes($this->notify_minutes_before))
            && now()->lt($this->available_from);
    }

    /**
     * Send a database notification to every student enrolled in this quiz's course
     * offering, and mark it as notified so it's never sent twice.
     */
    public function sendStartingSoonNotification(): void
    {
        $students = $this->assign_to_all
            ? $this->courseOffering->students
            : $this->assignedStudents;
        foreach ($students as $student) {
            Notification::make()
                ->title("\"{$this->title}\" starts soon")
                ->body("{$this->courseOffering->display_name} — opens at {$this->available_from->format('F j, Y g:i A')}.")
                ->icon('heroicon-o-clock')
                ->iconColor('warning')
                ->actions([
                    Action::make('view')
                        ->label('View')
                        ->url(TakeQuiz::getUrl(['quiz' => $this->id], panel: 'admin', tenant: $this->campus)),
                ])
                ->sendToDatabase($student);
        }
        $this->update(['notified_at' => now()]);
    }

    /**
     * Check every published, upcoming, scheduled-alert quiz and send any that are due.
     * Called on ordinary page loads (see QuizNotificationServiceProvider) — there's no
     * queue worker/cron guaranteed to be running on a classroom LAN box.
     */
    public static function sendDueStartingSoonNotifications(): void
    {
        static::query()
            ->where('is_published', true)
            ->whereNotNull('available_from')
            ->whereNotNull('notify_minutes_before')
            ->whereNull('notified_at')
            ->where('available_from', '>', now())
            ->with(['courseOffering.students', 'courseOffering.course', 'assignedStudents'])
            ->get()
            ->filter(fn (self $quiz) => $quiz->isDueForStartingSoonNotification())
            ->each(fn (self $quiz) => $quiz->sendStartingSoonNotification());
    }
}
