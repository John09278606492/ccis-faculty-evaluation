<?php

namespace App\Models;

use App\Support\CodeProject;
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
        if ($this->type === 'code') {
            if (! array_key_exists($this->codeLanguage(), self::CODE_LANGUAGES)) {
                $issues[] = 'Choose a programming language for the code editor.';
            }
            $files = CodeProject::normalize($this->options['files'] ?? null);
            if (isset($this->options['files']) && $files === null) {
                $issues[] = 'The starter files are malformed.';
            }
            array_push($issues, ...CodeProject::issues($this->starterFiles()));
            if (mb_strlen($this->databaseStarterSql()) > CodeProject::MAX_LENGTH) {
                $issues[] = 'The starter SQL may not exceed '.number_format(CodeProject::MAX_LENGTH).' characters.';
            }
            if ($this->referenceFiles() && ($referenceIssues = CodeProject::issues($this->referenceFiles()))) {
                $issues[] = 'Reference solution: '.implode(' ', $referenceIssues);
            }
            if ($this->options['checks']['enabled'] ?? false) {
                if (! $this->canAutoCheck()) {
                    $issues[] = 'Automatic checking works for PHP, web page and SQL questions without a student database.';
                } elseif ($this->referenceFiles() === []) {
                    $issues[] = 'Add a reference solution to check answers automatically.';
                }
                if (count($this->options['checks']['cases'] ?? []) > self::MAX_CHECK_CASES) {
                    $issues[] = 'Use at most '.self::MAX_CHECK_CASES.' test cases.';
                }
            }
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
     * Language presets for code questions. The preset picks the starter files;
     * each file is highlighted by its own extension. Web, PHP and SQL projects
     * can run in the student's browser (see CodeProject::runner()).
     */
    public const CODE_LANGUAGES = [
        'web' => 'Web page (HTML + CSS + JS)',
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
    public const MAX_CODE_LENGTH = CodeProject::MAX_LENGTH;

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
     * Code questions keep their language preset, starter files and run setting in
     * `options`, and the instructor-only reference solution in `correct_answer`.
     * Single-file questions saved before file tabs existed used `starter_code`
     * and `reference` strings instead; those still load as one file.
     */
    public function codeLanguage(): string
    {
        return $this->options['language'] ?? 'plaintext';
    }

    /**
     * @return list<array{name: string, content: string}>
     */
    public function starterFiles(): array
    {
        return CodeProject::normalize($this->options['files'] ?? null)
            ?? CodeProject::preset($this->codeLanguage(), (string) ($this->options['starter_code'] ?? ''));
    }

    /**
     * Starter files plus any empty starter folders, as stored project JSON.
     */
    public function starterProject(): string
    {
        return CodeProject::encode($this->starterFiles(), $this->options['folders'] ?? []);
    }

    /**
     * Reference solution files (empty when the instructor gave none).
     *
     * @return list<array{name: string, content: string}>
     */
    public function referenceFiles(): array
    {
        if (($files = CodeProject::normalize($this->correct_answer['files'] ?? null)) !== null) {
            return $files;
        }
        $legacy = (string) ($this->correct_answer['reference'] ?? '');

        return $legacy === '' ? [] : CodeProject::decode($legacy, $this->starterFiles());
    }

    /**
     * A student's saved answer as files; unanswered questions show the starter files.
     *
     * @return list<array{name: string, content: string}>
     */
    public function answerFiles(?string $answer): array
    {
        return CodeProject::decode($answer, $this->starterFiles());
    }

    /**
     * Whether each student gets their own MySQL database (used through phpMyAdmin)
     * for this code question.
     */
    public function hasDatabase(): bool
    {
        return $this->type === 'code' && (bool) ($this->options['database']['enabled'] ?? false);
    }

    /**
     * SQL loaded into each student's database when it is created.
     */
    public function databaseStarterSql(): string
    {
        return (string) ($this->options['database']['starter_sql'] ?? '');
    }

    public function codeRunner(): ?string
    {
        return CodeProject::runner($this->starterFiles());
    }

    /**
     * Whether students may add, rename and delete files and folders in their workspace.
     * On unless the instructor turned it off, like a normal VS Code workspace.
     */
    public function studentsCanManageFiles(): bool
    {
        return (bool) ($this->options['allow_student_files'] ?? true);
    }

    /**
     * Whether the editor offers code suggestions (autocomplete) for this question.
     * On unless the instructor turned it off, so a student gets help writing
     * their code without the instructor having to set anything up.
     */
    public function allowsAutocomplete(): bool
    {
        return (bool) ($this->options['autocomplete'] ?? true);
    }

    /** Runners whose output automatic checking can compare (see resources/js/components/code-checker.js). */
    public const AUTO_CHECK_RUNNERS = ['php', 'web', 'sql'];

    public const MAX_CHECK_CASES = 20;

    public const MAX_CHECK_INPUT = 2_000;

    public function canAutoCheck(): bool
    {
        return $this->type === 'code' && ! $this->hasDatabase() && in_array($this->codeRunner(), self::AUTO_CHECK_RUNNERS, true);
    }

    /**
     * Whether answers are checked by running them and the reference solution
     * on the same test cases and comparing their output.
     */
    public function autoChecks(): bool
    {
        return $this->canAutoCheck() && ($this->options['checks']['enabled'] ?? false) && $this->referenceFiles() !== [];
    }

    /**
     * Test cases, each with an optional name and input; at least one (an empty
     * input runs the project as it is).
     *
     * @return list<array{label: string, input: string}>
     */
    public function checkCases(): array
    {
        $cases = collect($this->options['checks']['cases'] ?? [])
            ->filter(fn ($case) => is_array($case))
            ->map(fn (array $case) => [
                'label' => mb_substr(trim((string) ($case['label'] ?? '')), 0, 80),
                'input' => mb_substr((string) ($case['input'] ?? ''), 0, self::MAX_CHECK_INPUT),
            ])
            ->take(self::MAX_CHECK_CASES)
            ->values()
            ->all();

        return $cases ?: [['label' => '', 'input' => '']];
    }

    public function checkIgnoresCase(): bool
    {
        return (bool) ($this->options['checks']['ignore_case'] ?? false);
    }

    /**
     * What the in-browser checker needs for this question.
     */
    public function checkConfig(): array
    {
        return [
            'runner' => $this->codeRunner(),
            'cases' => $this->checkCases(),
            'ignoreCase' => $this->checkIgnoresCase(),
            'points' => $this->points,
            'reference' => $this->referenceFiles(),
        ];
    }

    public function studentsCanRunCode(): bool
    {
        return ($this->options['allow_run'] ?? true) && $this->codeRunner() !== null;
    }

    /**
     * Keep only the question's own files from a submitted answer, in starter order,
     * unless students may manage files. Returns null when the answer is not a valid
     * file list for this question.
     */
    public function normalizeCodeAnswer(string $answer): ?string
    {
        return $this->codeAnswerIssue($answer) === null ? $this->normalizedCodeAnswer($answer) : null;
    }

    /**
     * Why a submitted code answer cannot be saved, or null when it can.
     */
    public function codeAnswerIssue(string $answer): ?string
    {
        $decoded = json_decode($answer, true);
        $submitted = CodeProject::normalize(is_array($decoded) ? ($decoded['files'] ?? null) : null);
        if ($submitted === null) {
            return 'Your code could not be saved. Reload the page and try again.';
        }
        if ($this->studentsCanManageFiles() && ($issues = CodeProject::issues($submitted)) !== []) {
            return 'Your code could not be saved: '.implode(' ', $issues);
        }
        if ($this->normalizedCodeAnswer($answer) === null) {
            return 'Your code could not be saved: it may not exceed '.number_format(self::MAX_CODE_LENGTH).' characters in total.';
        }

        return null;
    }

    protected function normalizedCodeAnswer(string $answer): ?string
    {
        $decoded = json_decode($answer, true);
        $submitted = CodeProject::normalize(is_array($decoded) ? ($decoded['files'] ?? null) : null);
        if ($submitted === null) {
            return null;
        }
        if ($this->studentsCanManageFiles()) {
            // The student shapes the workspace: any valid set of files and folders.
            return CodeProject::issues($submitted) === []
                ? CodeProject::encode($submitted, CodeProject::decodeFolders($answer))
                : null;
        }
        $byName = array_column($submitted, 'content', 'name');
        $files = array_map(
            fn (array $file) => ['name' => $file['name'], 'content' => $byName[$file['name']] ?? $file['content']],
            $this->starterFiles(),
        );

        return CodeProject::length($files) <= CodeProject::MAX_LENGTH ? CodeProject::encode($files) : null;
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
