<?php

namespace App\Models;

use App\Services\AutoSyncClassRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'score',
        'max_score',
        'ip_address',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'submitted_at' => 'datetime',
        'score' => 'decimal:2',
        'max_score' => 'integer',
    ];

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(QuizAnswer::class);
    }

    /** The supervision log, see App\Services\ExamSupervision. */
    public function events(): HasMany
    {
        return $this->hasMany(QuizAttemptEvent::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && now()->gt($this->expires_at);
    }

    /**
     * Whether any answers on this attempt still need manual grading.
     */
    public function hasUngradedAnswers(): bool
    {
        return $this->answers()
            ->whereNull('points_awarded')
            ->whereHas('question', fn ($q) => $q->whereIn('type', QuizQuestion::MANUALLY_GRADED_TYPES))
            ->exists();
    }
}
