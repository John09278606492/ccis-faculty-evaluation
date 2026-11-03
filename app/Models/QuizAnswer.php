<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'quiz_attempt_id',
        'quiz_question_id',
        'answer_text',
        'is_correct',
        'points_awarded',
        'feedback',
        'database_name',
        'database_export',
        'database_snapshot_status',
        'database_snapshot_error',
        'database_snapshot_hash',
        'database_snapshot_at',
        'database_snapshot_attempted_at',
        'auto_check',
    ];

    protected $casts = [
        'is_correct' => 'boolean',
        'points_awarded' => 'decimal:2',
        'auto_check' => 'array',
        'database_snapshot_at' => 'datetime',
        'database_snapshot_attempted_at' => 'datetime',
    ];

    public function hasVerifiedDatabaseSnapshot(): bool
    {
        return $this->database_snapshot_status === 'ready'
            && is_string($this->database_export) && $this->database_export !== ''
            && is_string($this->database_snapshot_hash)
            && hash_equals($this->database_snapshot_hash, hash('sha256', $this->database_export));
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class, 'quiz_attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(QuizQuestion::class, 'quiz_question_id');
    }
}
