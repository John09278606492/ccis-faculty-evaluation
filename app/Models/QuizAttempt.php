<?php

namespace App\Models;

use App\Services\AutoSyncClassRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class QuizAttempt extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(function (self $attempt) {
            if ($attempt->status === 'graded' && $attempt->score !== null && $attempt->wasChanged(['status', 'score'])) {
                AutoSyncClassRecord::afterCommit($attempt->quiz_id);
            } elseif ($attempt->wasRecentlyCreated && $attempt->status === 'graded' && $attempt->score !== null) {
                AutoSyncClassRecord::afterCommit($attempt->quiz_id);
            }
        });
    }

    protected $fillable = [
        'quiz_id',
        'user_id',
        'attempt_number',
        'started_at',
        'expires_at',
        'submitted_at',
        'status',
        'finalized_reason',
        'score',
        'max_score',
        'ip_address',
        'focus_leaves',
        'focus_away_seconds',
        'focus_log',
        'pastes',
        'pasted_chars',
        'paste_log',
        'device_hash',
        'blocked_device_hash',
        'device_blocks',
    ];

    /** Pastes shorter than this are ignored: a word or a variable name is not an answer. */
    public const PASTE_MIN_CHARS = 40;

    /** Instructors are alerted when a student's exam-page leaves reach these counts. */
    public const FOCUS_ALERT_AT = [1, 3, 5, 10];

    /** Entries kept in focus_log; the counters keep counting past it. */
    public const FOCUS_LOG_LIMIT = 100;

    protected $hidden = ['device_hash', 'blocked_device_hash'];

    protected $casts = [
        'attempt_number' => 'integer',
        'device_blocks' => 'integer',
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'submitted_at' => 'datetime',
        'score' => 'decimal:2',
        'max_score' => 'integer',
        'focus_leaves' => 'integer',
        'focus_away_seconds' => 'integer',
        'focus_log' => 'array',
        'pastes' => 'integer',
        'pasted_chars' => 'integer',
        'paste_log' => 'array',
    ];

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function answers(): HasMany
    {
        return $this->hasMany(QuizAnswer::class);
    }

    /**
     * Record that the student left the exam: 'tab' when the page was hidden (another
     * tab, minimized), 'window' when another window took focus while the page stayed
     * visible (side by side). Returns false when a leave is already open, so one
     * departure is never counted twice.
     *
     * Saved quietly: a live update to the whole class on every tab switch would
     * reload every student's page. The instructor is told by notification instead.
     */
    public function recordFocusLost(string $kind = 'tab'): bool
    {
        $log = $this->focus_log ?? [];
        if ($log !== [] && end($log)['back_at'] === null) {
            return false;
        }

        $log[] = ['left_at' => now()->toIso8601String(), 'back_at' => null, 'kind' => $kind === 'window' ? 'window' : 'tab'];
        $this->focus_log = array_slice($log, -self::FOCUS_LOG_LIMIT);
        $this->focus_leaves++;
        $this->saveQuietly();

        return true;
    }

    /**
     * Record that the student came back, adding the time away. The server's clock
     * measures it, so a student cannot shorten it.
     */
    public function recordFocusReturned(): bool
    {
        $log = $this->focus_log ?? [];
        $last = array_key_last($log);
        if ($last === null || $log[$last]['back_at'] !== null) {
            return false;
        }

        $back = $this->expires_at && now()->gt($this->expires_at) ? $this->expires_at : now();
        $log[$last]['back_at'] = $back->toIso8601String();
        $this->focus_away_seconds += max(0, (int) Carbon::parse($log[$last]['left_at'])->diffInSeconds($back));
        $this->focus_log = $log;
        $this->saveQuietly();

        return true;
    }

    /**
     * Record text pasted into the exam that was not copied from the exam itself.
     */
    public function recordPaste(int $chars, int $lines): void
    {
        $log = $this->paste_log ?? [];
        $log[] = ['at' => now()->toIso8601String(), 'chars' => $chars, 'lines' => $lines];
        $this->paste_log = array_slice($log, -self::FOCUS_LOG_LIMIT);
        $this->pastes++;
        $this->pasted_chars += $chars;
        $this->saveQuietly();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && now()->gt($this->expires_at);
    }

    /**
     * Whether any answers on this attempt still need manual grading.
     */
    /**
     * Total the answers' points; the attempt is graded once no manually graded
     * answer is waiting for points.
     */
    public function recalculateScore(): void
    {
        $this->update([
            'score' => $this->answers()->sum('points_awarded'),
            'status' => $this->hasUngradedAnswers() ? 'submitted' : 'graded',
        ]);
    }

    public function hasUngradedAnswers(): bool
    {
        return $this->answers()
            ->whereNull('points_awarded')
            ->whereHas('question', fn ($q) => $q->whereIn('type', QuizQuestion::MANUALLY_GRADED_TYPES))
            ->exists();
    }
}
