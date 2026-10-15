<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in an attempt's supervision log (see App\Services\ExamSupervision):
 * the student left the exam page ("away"), or pasted text from outside the exam ("paste").
 */
class QuizAttemptEvent extends Model
{
    public const AWAY = 'away';

    public const PASTE = 'paste';

    protected $fillable = [
        'quiz_attempt_id',
        'quiz_question_id',
        'type',
        'started_at',
        'ended_at',
        'seconds',
        'details',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'seconds' => 'integer',
        'details' => 'array',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class, 'quiz_attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(QuizQuestion::class, 'quiz_question_id');
    }

    public static function formatSeconds(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return sprintf('%dm %02ds', $minutes, $seconds % 60);
        }

        return sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
    }
}
