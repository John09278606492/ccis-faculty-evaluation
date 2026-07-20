<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'quiz_id',
        'type',
        'question_text',
        'points',
        'order',
        'options',
        'correct_answer',
    ];

    protected $casts = [
        'points' => 'integer',
        'order' => 'integer',
        'options' => 'array',
        'correct_answer' => 'array',
    ];

    /**
     * Available question types.
     */
    public const TYPES = [
        'multiple_choice' => 'Multiple Choice',
        'true_false' => 'True / False',
        'short_answer' => 'Short Answer (auto-graded)',
        'essay' => 'Essay (manually graded)',
        'file_upload' => 'File Upload (manually graded)',
    ];

    /**
     * Types that are graded automatically at submission time.
     */
    public const AUTO_GRADED_TYPES = [
        'multiple_choice',
        'true_false',
        'short_answer',
    ];

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function requiresOptions(): bool
    {
        return in_array($this->type, ['multiple_choice', 'true_false']);
    }

    public function isAutoGraded(): bool
    {
        return in_array($this->type, self::AUTO_GRADED_TYPES);
    }

    /**
     * Grade a raw answer value against this question's correct_answer.
     * Only meaningful for auto-graded types; returns null otherwise.
     */
    public function grade(?string $answer): ?bool
    {
        if (! $this->isAutoGraded() || $answer === null) {
            return $this->isAutoGraded() ? false : null;
        }

        $accepted = $this->correct_answer ?? [];

        return match ($this->type) {
            'multiple_choice', 'true_false' => in_array($answer, $accepted, true),
            'short_answer' => collect($accepted)
                ->contains(fn ($accepted) => mb_strtolower(trim($accepted)) === mb_strtolower(trim($answer))),
            default => null,
        };
    }
}
