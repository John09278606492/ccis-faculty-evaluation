<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class QuizQuestion extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        $guard = function (self $question) {
            if ($question->quiz->attempts()->exists()) {
                throw ValidationException::withMessages(['questions' => 'This quiz already has attempts. Duplicate it before changing questions.']);
            }
        };
        static::creating($guard);
        static::created(function (self $question) {
            if ($question->quiz->is_published && $question->validationIssues()) {
                $question->quiz->update(['is_published' => false]);
            }
        });
        static::updating($guard);
        static::deleting($guard);
    }

    public function validationIssues(): array
    {
        $issues = [];
        if (blank($this->question_text) || $this->points < 1) {
            $issues[] = 'Enter a question and at least one point.';
        }
        if ($this->type === 'multiple_choice') {
            $options = collect($this->options ?? []);
            if ($options->count() < 2 || $options->contains(fn ($o) => blank($o['label'] ?? null))) {
                $issues[] = 'Add at least two nonempty choices.';
            }
            if (count($this->correct_answer ?? []) !== 1 || ! in_array($this->correct_answer[0] ?? null, $options->pluck('key')->all(), true)) {
                $issues[] = 'Mark exactly one correct choice; students select one answer.';
            }
        }
        if ($this->type === 'true_false' && (count($this->correct_answer ?? []) !== 1 || ! in_array($this->correct_answer[0], ['true', 'false'], true))) {
            $issues[] = 'Choose True or False as the answer key.';
        }
        if ($this->type === 'short_answer' && collect($this->correct_answer ?? [])->filter(fn ($answer) => filled($answer))->isEmpty()) {
            $issues[] = 'Add at least one accepted answer.';
        }
        if ($this->type === 'code' && ! array_key_exists($this->codeLanguage(), self::CODE_LANGUAGES)) {
            $issues[] = 'Choose a programming language for the code editor.';
        }

        return $issues;
    }

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
        'code' => 'Code (manually graded)',
    ];

    /**
     * Languages the code editor can highlight. Code answers are never executed;
     * the language only controls syntax highlighting and indentation.
     */
    public const CODE_LANGUAGES = [
        'python' => 'Python',
        'java' => 'Java',
        'c' => 'C',
        'cpp' => 'C++',
        'csharp' => 'C#',
        'javascript' => 'JavaScript',
        'php' => 'PHP',
        'sql' => 'SQL',
        'html' => 'HTML',
        'css' => 'CSS',
        'plaintext' => 'Plain text',
    ];

    /**
     * Types that are graded automatically at submission time.
     */
    public const AUTO_GRADED_TYPES = [
        'multiple_choice',
        'true_false',
        'short_answer',
    ];

    /** Longest code a student answer, starter code or reference solution may be. */
    public const MAX_CODE_LENGTH = 50_000;

    /**
     * Types the instructor grades by hand in the Attempts tab.
     */
    public const MANUALLY_GRADED_TYPES = [
        'essay',
        'file_upload',
        'code',
    ];

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function requiresOptions(): bool
    {
        return in_array($this->type, ['multiple_choice', 'true_false']);
    }

    /**
     * Code questions keep their editor settings in `options` and the
     * instructor-only reference solution in `correct_answer`.
     */
    public function codeLanguage(): string
    {
        return $this->options['language'] ?? 'plaintext';
    }

    public function starterCode(): string
    {
        return (string) ($this->options['starter_code'] ?? '');
    }

    public function referenceSolution(): string
    {
        return (string) ($this->correct_answer['reference'] ?? '');
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
