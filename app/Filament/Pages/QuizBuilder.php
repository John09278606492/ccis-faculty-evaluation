<?php

namespace App\Filament\Pages;

use App\Models\Quiz;
use App\Models\QuizQuestion;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class QuizBuilder extends Page
{
    protected static string $view = 'filament.pages.quiz-builder';

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public ?int $quizId = null;

    #[Locked]
    public ?Quiz $quizRecord = null;

    public array $questions = [];

    #[Locked]
    public ?int $selectedQuestionId = null;

    public array $selectedQuestionData = [];

    public bool $showProperties = false;

    #[Locked]
    public array $savedQuestionData = [];

    /**
     * Available question types, grouped like FormBuilder's field palette.
     */
    public static function getQuestionTypeCategories(): array
    {
        return [
            'Auto-Graded' => [
                'multiple_choice' => ['label' => 'Multiple Choice', 'icon' => '◉'],
                'true_false' => ['label' => 'True / False', 'icon' => '✓'],
                'short_answer' => ['label' => 'Short Answer', 'icon' => 'T'],
            ],
            'Manually Graded' => [
                'essay' => ['label' => 'Essay', 'icon' => '¶'],
                'file_upload' => ['label' => 'File Upload', 'icon' => '📎'],
                'code' => ['label' => 'Code', 'icon' => '</>'],
            ],
        ];
    }

    public function mount(int $record): void
    {
        $this->quizId = $record;
        $this->quizRecord = Quiz::findOrFail($record);
        $user = auth()->user();
        abort_unless(
            $user->hasRole('super_admin')
                || ($user->can('update_quiz') && $this->quizRecord->courseOffering->user_id === $user->id),
            403,
            'You do not have permission to edit this quiz.'
        );
        abort_unless((int) $this->quizRecord->campus?->id === (int) filament()->getTenant()?->id, 403);
        $this->loadQuestions();
    }

    protected function authorizeEditing(): void
    {
        abort_unless(auth()->user()->can('update', $this->quizRecord), 403);
        if ($this->quizRecord->attempts()->exists()) {
            throw ValidationException::withMessages(['selectedQuestionData' => 'Students have already started this quiz. Duplicate the quiz to change its questions without affecting their grades.']);
        }
    }

    protected function savePendingChanges(): void
    {
        if ($this->selectedQuestionId && $this->selectedQuestionData !== $this->savedQuestionData) {
            $this->saveQuestionProperties();
        }
    }

    public function loadQuestions(): void
    {
        $this->quizRecord->refresh();
        $this->questions = $this->quizRecord->questions()
            ->orderBy('order')
            ->get()
            ->map(fn (QuizQuestion $q) => [
                'id' => $q->id,
                'type' => $q->type,
                'question_text' => $q->question_text,
                'points' => $q->points,
                'order' => $q->order,
            ])
            ->toArray();
    }

    /**
     * Add a new question to the canvas, optionally right after an existing one (drag-drop insert).
     */
    public function addQuestion(string $type, ?int $afterQuestionId = null): void
    {
        $this->authorizeEditing();
        $this->savePendingChanges();
        abort_unless(array_key_exists($type, QuizQuestion::TYPES), 422);
        $typeLabel = QuizQuestion::TYPES[$type];
        $existingCount = collect($this->questions)->where('type', $type)->count();
        if ($afterQuestionId) {
            $afterQuestion = collect($this->questions)->firstWhere('id', $afterQuestionId);
            $order = $afterQuestion ? $afterQuestion['order'] + 1 : count($this->questions) + 1;
            QuizQuestion::where('quiz_id', $this->quizId)
                ->where('order', '>=', $order)
                ->increment('order');
        } else {
            $order = count($this->questions) + 1;
        }
        $defaults = match ($type) {
            'multiple_choice' => [
                'options' => [
                    ['key' => '0', 'label' => 'Option 1'],
                    ['key' => '1', 'label' => 'Option 2'],
                ],
                'correct_answer' => [],
            ],
            'true_false' => [
                'options' => [
                    ['key' => 'true', 'label' => 'True'],
                    ['key' => 'false', 'label' => 'False'],
                ],
                'correct_answer' => ['true'],
            ],
            'code' => [
                'options' => ['language' => 'python', 'starter_code' => ''],
                'correct_answer' => null,
            ],
            default => ['options' => null, 'correct_answer' => null],
        };
        $question = QuizQuestion::create([
            'quiz_id' => $this->quizId,
            'type' => $type,
            'question_text' => $typeLabel.($existingCount > 0 ? ' '.($existingCount + 1) : ''),
            'points' => 1,
            'order' => $order,
            ...$defaults,
        ]);
        $this->loadQuestions();
        $this->selectQuestion($question->id);
    }

    /**
     * Select a question and load its properties into the right panel.
     */
    public function selectQuestion(int $questionId): void
    {
        $this->savePendingChanges();
        $this->resetValidation();
        $question = QuizQuestion::find($questionId);
        if (! $question || $question->quiz_id !== $this->quizId) {
            return;
        }
        $this->selectedQuestionId = $questionId;
        $this->selectedQuestionData = [
            'type' => $question->type,
            'question_text' => $question->question_text,
            'points' => $question->points,
            'options' => $this->optionsForEditing($question),
            'correct_answer' => $question->type === 'short_answer' ? ($question->correct_answer ?? []) : [],
            'true_false_correct' => $question->type === 'true_false' ? ($question->correct_answer[0] ?? 'true') : 'true',
            'code_language' => $question->type === 'code' ? $question->codeLanguage() : 'python',
            'starter_code' => $question->type === 'code' ? $question->starterCode() : '',
            'reference_solution' => $question->type === 'code' ? $question->referenceSolution() : '',
        ];
        $this->savedQuestionData = $this->selectedQuestionData;
        $this->showProperties = true;
        $this->dispatch('question-saved');
        $this->dispatch('question-selected');
    }

    /**
     * Reshape stored {key,label} options + correct_answer keys into the
     * builder-friendly {label,is_correct} shape used by the properties panel.
     */
    protected function optionsForEditing(QuizQuestion $question): array
    {
        if ($question->type !== 'multiple_choice') {
            return [];
        }
        $correctKeys = $question->correct_answer ?? [];

        return collect($question->options ?? [])
            ->map(fn ($option) => [
                'label' => $option['label'] ?? '',
                'is_correct' => in_array($option['key'] ?? null, $correctKeys, true),
            ])
            ->values()
            ->toArray();
    }

    /**
     * Save properties from the right panel back to the normalized columns.
     */
    public function saveQuestionProperties(): void
    {
        $this->authorizeEditing();
        $this->validate([
            'selectedQuestionData.question_text' => ['required', 'string', 'max:10000'],
            'selectedQuestionData.points' => ['required', 'integer', 'min:1', 'max:10000'],
            'selectedQuestionData.starter_code' => ['nullable', 'string', 'max:'.QuizQuestion::MAX_CODE_LENGTH],
            'selectedQuestionData.reference_solution' => ['nullable', 'string', 'max:'.QuizQuestion::MAX_CODE_LENGTH],
        ]);
        if (! $this->selectedQuestionId) {
            return;
        }
        $question = QuizQuestion::find($this->selectedQuestionId);
        if (! $question || $question->quiz_id !== $this->quizId) {
            return;
        }
        $data = [
            'question_text' => $this->selectedQuestionData['question_text'] ?? $question->question_text,
            'points' => max(1, (int) ($this->selectedQuestionData['points'] ?? 1)),
        ];
        if ($question->type === 'multiple_choice') {
            $options = [];
            $correct = [];
            foreach (collect($this->selectedQuestionData['options'] ?? [])->values() as $i => $option) {
                $key = (string) $i;
                $options[] = ['key' => $key, 'label' => $option['label'] ?? ''];
                if (! empty($option['is_correct'])) {
                    $correct[] = $key;
                }
            }
            $data['options'] = $options;
            $data['correct_answer'] = $correct;
        } elseif ($question->type === 'true_false') {
            $data['correct_answer'] = [$this->selectedQuestionData['true_false_correct'] ?? 'true'];
        } elseif ($question->type === 'short_answer') {
            $data['correct_answer'] = array_values(array_filter(
                $this->selectedQuestionData['correct_answer'] ?? [],
                fn ($answer) => trim((string) $answer) !== ''
            ));
        } elseif ($question->type === 'code') {
            $data['options'] = [
                'language' => (string) ($this->selectedQuestionData['code_language'] ?? 'plaintext'),
                'starter_code' => (string) ($this->selectedQuestionData['starter_code'] ?? ''),
            ];
            $reference = (string) ($this->selectedQuestionData['reference_solution'] ?? '');
            $data['correct_answer'] = $reference !== '' ? ['reference' => $reference] : null;
        }
        $candidate = new QuizQuestion(['type' => $question->type, ...$data]);
        if ($issues = $candidate->validationIssues()) {
            throw ValidationException::withMessages(['selectedQuestionData' => implode(' ', $issues)]);
        }
        $question->update($data);
        $this->savedQuestionData = $this->selectedQuestionData;
        $this->resetValidation();
        $this->dispatch('question-saved');
        $this->loadQuestions();
        $this->dispatch('notify', message: 'Question saved');
    }

    /**
     * Reorder questions from drag-and-drop.
     */
    public function reorderQuestions(array $orderedIds): void
    {
        $this->authorizeEditing();
        $expected = $this->quizRecord->questions()->pluck('id')->sort()->values()->all();
        $actual = collect($orderedIds)->map(fn ($id) => (int) $id)->sort()->values()->all();
        abort_unless($expected === $actual, 422);
        foreach ($orderedIds as $index => $id) {
            QuizQuestion::where('id', $id)
                ->where('quiz_id', $this->quizId)
                ->update(['order' => $index + 1]);
        }
        $this->loadQuestions();
    }

    public function deleteQuestion(int $questionId): void
    {
        $this->authorizeEditing();
        $question = QuizQuestion::find($questionId);
        if (! $question || $question->quiz_id !== $this->quizId) {
            return;
        }
        $question->delete();
        if ($this->selectedQuestionId === $questionId) {
            $this->selectedQuestionData = $this->savedQuestionData;
            $this->closeProperties();
        }
        $this->quizRecord->questions()->orderBy('order')->get()
            ->each(fn ($q, $i) => $q->update(['order' => $i + 1]));
        $this->loadQuestions();
    }

    public function duplicateQuestion(int $questionId): void
    {
        $this->authorizeEditing();
        $this->savePendingChanges();
        $question = QuizQuestion::find($questionId);
        if (! $question || $question->quiz_id !== $this->quizId) {
            return;
        }
        QuizQuestion::where('quiz_id', $this->quizId)
            ->where('order', '>', $question->order)
            ->increment('order');
        $new = $question->replicate();
        $new->question_text = $question->question_text.' (Copy)';
        $new->order = $question->order + 1;
        $new->save();
        $this->loadQuestions();
        $this->selectQuestion($new->id);
    }

    public function closeProperties(): void
    {
        $this->savePendingChanges();
        $this->selectedQuestionId = null;
        $this->selectedQuestionData = [];
        $this->showProperties = false;
    }

    public function addOption(): void
    {
        $count = count($this->selectedQuestionData['options'] ?? []);
        $this->selectedQuestionData['options'][] = [
            'label' => 'Option '.($count + 1),
            'is_correct' => false,
        ];
    }

    public function removeOption(int $index): void
    {
        unset($this->selectedQuestionData['options'][$index]);
        $this->selectedQuestionData['options'] = array_values($this->selectedQuestionData['options']);
    }

    public function addAcceptedAnswer(): void
    {
        $this->selectedQuestionData['correct_answer'][] = '';
    }

    public function removeAcceptedAnswer(int $index): void
    {
        unset($this->selectedQuestionData['correct_answer'][$index]);
        $this->selectedQuestionData['correct_answer'] = array_values($this->selectedQuestionData['correct_answer']);
    }

    public function moveQuestion(int $questionId, int $direction): void
    {
        $this->authorizeEditing();
        abort_unless(in_array($direction, [-1, 1], true), 422);
        $ids = $this->quizRecord->questions()->pluck('id')->all();
        $index = array_search($questionId, $ids, true);
        if ($index === false || ! isset($ids[$index + $direction])) {
            return;
        }
        [$ids[$index], $ids[$index + $direction]] = [$ids[$index + $direction], $ids[$index]];
        $this->reorderQuestions($ids);
    }

    public function getTitle(): string
    {
        return 'Quiz Builder — '.($this->quizRecord->title ?? 'Untitled');
    }

    public static function getRoutePath(): string
    {
        return '/quizzes/{record}/builder';
    }

    public static function getSlug(): string
    {
        return 'quizzes/{record}/builder';
    }
}
