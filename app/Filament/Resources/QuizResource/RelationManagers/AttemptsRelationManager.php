<?php

namespace App\Filament\Resources\QuizResource\RelationManagers;

use App\Filament\Concerns\ReceivesClassroomUpdates;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Services\BackgroundTasks;
use App\Support\SqliteWriter;
use Carbon\CarbonInterval;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Renderless;

class AttemptsRelationManager extends RelationManager
{
    use ReceivesClassroomUpdates;

    protected static string $relationship = 'attempts';

    protected static ?string $title = 'Attempts & Submissions';

    protected static ?string $recordTitleAttribute = 'id';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    protected ?int $questionCount = null;

    protected function receivesFocusUpdates(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('answers:id,quiz_attempt_id,auto_check')
                ->withCount(['answers as answered_count' => fn ($answers) => $answers->whereNotNull('answer_text')->where('answer_text', '!=', '')]))
            ->heading('Attempts & Submissions')
            ->description("Highest possible score: {$this->getOwnerRecord()->total_points} points")
            ->columns([
                Tables\Columns\TextColumn::make('user.student_id')
                    ->label('Student ID')
                    ->searchable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Student')
                    ->searchable(),

                Tables\Columns\TextColumn::make('attempt_number')
                    ->label('#'),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'in_progress' => 'warning',
                        'submitted' => 'info',
                        'graded' => 'success',
                        default => 'gray',
                    }),

                // Updates live while students work, so instructors can see who is stuck.
                Tables\Columns\TextColumn::make('answered')
                    ->label('Answered')
                    ->state(fn (QuizAttempt $record) => $record->answered_count.' / '.($this->questionCount ??= $this->getOwnerRecord()->questions()->count())),

                Tables\Columns\TextColumn::make('score')
                    ->label('Score')
                    ->sortable()
                    ->formatStateUsing(fn ($state, QuizAttempt $record) => $state === null ? 'Pending' : "{$state} / {$record->max_score}"),

                Tables\Columns\TextColumn::make('result')
                    ->label('Result')
                    ->state(fn (QuizAttempt $record) => $record->status !== 'graded' ? 'Pending' : match ($this->getOwnerRecord()->hasPassed($record->score)) {
                        true => 'Passed',
                        false => 'Failed',
                        null => '—',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Passed' => 'success',
                        'Failed' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('auto_check')
                    ->label('Auto-check')
                    ->visible(fn () => $this->getOwnerRecord()->questions()->where('type', 'code')->get()->contains(fn (QuizQuestion $question) => $question->autoChecks()))
                    ->state(function (QuizAttempt $record) {
                        $checks = $record->answers->pluck('auto_check')->filter();

                        return $checks->isEmpty() ? 'Not checked' : $checks->sum('passed').' of '.$checks->sum('total').' tests';
                    })
                    ->badge()
                    ->color(function (QuizAttempt $record) {
                        $checks = $record->answers->pluck('auto_check')->filter();

                        return match (true) {
                            $checks->isEmpty() => 'gray',
                            $checks->sum('passed') === $checks->sum('total') => 'success',
                            $checks->sum('passed') === 0 => 'danger',
                            default => 'warning',
                        };
                    }),

                Tables\Columns\TextColumn::make('focus_leaves')
                    ->label('Left page')
                    ->visible(fn () => $this->getOwnerRecord()->type === 'exam')
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state) => $state === 0 ? 'gray' : ($state >= 3 ? 'danger' : 'warning'))
                    ->formatStateUsing(fn (int $state) => $state === 0 ? 'Never' : ($state === 1 ? 'Once' : "{$state} times"))
                    ->description(fn (QuizAttempt $record) => $record->focus_leaves ? 'Away '.CarbonInterval::seconds($record->focus_away_seconds)->cascade()->forHumans(short: true) : null)
                    ->tooltip(fn (QuizAttempt $record) => collect($record->focus_log ?? [])
                        ->map(fn (array $leave) => (($leave['kind'] ?? 'tab') === 'window' ? 'Other window ' : 'Left page ')
                            .Carbon::parse($leave['left_at'])->timezone(config('app.timezone'))->format('g:i:s A').' to '
                            .($leave['back_at'] ? Carbon::parse($leave['back_at'])->timezone(config('app.timezone'))->format('g:i:s A') : 'not back'))
                        ->implode("\n") ?: null),

                Tables\Columns\TextColumn::make('pastes')
                    ->label('Pasted')
                    ->visible(fn () => $this->getOwnerRecord()->type === 'exam')
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state) => $state === 0 ? 'gray' : 'danger')
                    ->formatStateUsing(fn (int $state) => $state === 0 ? 'Never' : ($state === 1 ? 'Once' : "{$state} times"))
                    ->description(fn (QuizAttempt $record) => $record->pastes ? number_format($record->pasted_chars).' characters' : null)
                    ->tooltip(fn (QuizAttempt $record) => collect($record->paste_log ?? [])
                        ->map(fn (array $paste) => Carbon::parse($paste['at'])->timezone(config('app.timezone'))->format('g:i:s A')
                            .': '.number_format($paste['chars']).' characters, '.$paste['lines'].' '.str('line')->plural($paste['lines']))
                        ->implode("\n") ?: null),

                Tables\Columns\TextColumn::make('device_blocks')
                    ->label('Other device')
                    ->visible(fn () => $this->getOwnerRecord()->type === 'exam')
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state) => $state === 0 ? 'gray' : 'danger')
                    ->formatStateUsing(fn (int $state) => $state === 0 ? 'None' : ($state === 1 ? 'Blocked once' : "Blocked {$state} times"))
                    ->description(fn (QuizAttempt $record) => $record->status === 'in_progress' && $record->blocked_device_hash ? 'Waiting for your approval' : null),

                Tables\Columns\TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('submitted_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Not submitted'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'in_progress' => 'In Progress',
                        'submitted' => 'Submitted (needs grading)',
                        'graded' => 'Graded',
                    ]),
            ], layout: FiltersLayout::AboveContent)
            ->actions([
                Tables\Actions\Action::make('grade')
                    ->label('Grade')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary')
                    // Reaching this relation manager already required update_quiz (EditQuiz's
                    // page-level authorization), but this is checked again explicitly here too.
                    ->visible(fn (QuizAttempt $record) => $record->status !== 'in_progress'
                        && (auth()->user()?->can('update', $this->getOwnerRecord()) ?? false))
                    ->form(fn (QuizAttempt $record) => $this->buildGradingSchema($record))
                    ->action(fn (QuizAttempt $record, array $data) => $this->saveGrades($record, $data)),
                Tables\Actions\Action::make('allowNewDevice')
                    ->label('Allow new device')
                    ->icon('heroicon-o-device-phone-mobile')
                    ->color('warning')
                    ->visible(fn (QuizAttempt $record) => $record->status === 'in_progress' && filled($record->blocked_device_hash)
                        && (auth()->user()?->can('update', $this->getOwnerRecord()) ?? false))
                    ->requiresConfirmation()
                    ->modalDescription('Only do this when the student is in front of you and their first computer really stopped working. The exam moves to the new device and closes on the old one; everything already saved is kept.')
                    ->action(function (QuizAttempt $record) {
                        abort_unless(auth()->user()?->can('update', $this->getOwnerRecord()) && $record->quiz_id === $this->getOwnerRecord()->id, 403);
                        $moved = QuizAttempt::whereKey($record->id)->where('status', 'in_progress')->whereNotNull('blocked_device_hash')
                            ->update(['device_hash' => DB::raw('blocked_device_hash'), 'blocked_device_hash' => null]);
                        Notification::make()->title($moved ? 'New device allowed' : 'Nothing to allow')
                            ->body($moved ? 'Ask the student to reload the exam page on the new device.' : null)->success()->send();
                    }),
                // Only the student's latest attempt, so the next one takes its number again.
                Tables\Actions\Action::make('resetAttempt')
                    ->label('Reset attempt')
                    ->icon('heroicon-o-arrow-path')
                    ->color('danger')
                    ->visible(fn (QuizAttempt $record) => (auth()->user()?->can('update', $this->getOwnerRecord()) ?? false)
                        && ! QuizAttempt::where('quiz_id', $record->quiz_id)->where('user_id', $record->user_id)->where('attempt_number', '>', $record->attempt_number)->exists())
                    ->requiresConfirmation()
                    ->modalHeading(fn (QuizAttempt $record) => 'Reset '.($record->user?->name ?? 'this student')."'s attempt?")
                    ->modalDescription('This deletes the attempt and every answer in it, so the student can start again from the beginning with the full time. Use it when a student was submitted by mistake. It cannot be undone.')
                    ->modalSubmitActionLabel('Delete attempt and let them start again')
                    ->action(function (QuizAttempt $record) {
                        abort_unless(auth()->user()?->can('update', $this->getOwnerRecord()) && $record->quiz_id === $this->getOwnerRecord()->id, 403);
                        DB::transaction(function () use ($record) {
                            SqliteWriter::reserve('quiz_attempts', $record->id);
                            $record->delete();
                        });
                        Notification::make()->title('Attempt reset')
                            ->body('The student can sign in and start again. If the exam is open in their browser, ask them to reload it.')->success()->send();
                    }),
            ])
            ->headerActions([
                Tables\Actions\Action::make('autoCheck')
                    ->label('Auto-check code')
                    ->icon('heroicon-o-beaker')
                    ->color('gray')
                    ->visible(fn () => (auth()->user()?->can('update', $this->getOwnerRecord()) ?? false)
                        && $this->getOwnerRecord()->questions()->where('type', 'code')->get()->contains(fn (QuizQuestion $question) => $question->autoChecks()))
                    ->modalHeading('Check code answers automatically')
                    ->modalDescription("Runs every submitted code answer and your reference solution on the question's test cases in this browser, then fills in the suggested score for answers you have not graded yet. Scores you set by hand are kept, and you can change any score with Grade.")
                    ->modalContent(function () {
                        $answers = $this->autoCheckedAnswers();

                        return $this->checkerBlock(
                            'bulk',
                            $answers->pluck('question')->unique('id')->mapWithKeys(fn (QuizQuestion $question) => [$question->id => $question->checkConfig()])->all(),
                            $answers->map(fn (QuizAnswer $answer) => [
                                'id' => $answer->id,
                                'questionId' => $answer->quiz_question_id,
                                'label' => ($answer->attempt->user?->name ?? 'Student').' · '.str($answer->question->question_text)->limit(50),
                            ])->all(),
                        );
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('3xl'),

                Tables\Actions\Action::make('exportResults')->label('Export results CSV')->icon('heroicon-o-arrow-down-tray')
                    ->action(fn () => app(BackgroundTasks::class)->enqueue(auth()->user(), filament()->getTenant(), 'assessment-export', 'Export assessment results',
                        ['quiz_id' => $this->getOwnerRecord()->id, 'ids' => $this->getFilteredTableQuery()->pluck('quiz_attempts.id')->all()])),
            ])
            ->bulkActions([])
            ->defaultSort('started_at', 'desc');
    }

    /**
     * Build the grading modal: a read-only recap of the auto-graded questions
     * (so the instructor sees the full score breakdown, not just the essays in
     * isolation), followed by one editable section per manually-graded answer.
     *
     * @return array<int, Forms\Components\Component>
     */
    protected function buildGradingSchema(QuizAttempt $record): array
    {
        $autoGraded = $record->answers()
            ->with('question')
            ->whereHas('question', fn ($q) => $q->whereIn('type', QuizQuestion::AUTO_GRADED_TYPES))
            ->get();

        $manualAnswers = $record->answers()
            ->with('question')
            ->whereHas('question', fn ($q) => $q->whereIn('type', QuizQuestion::MANUALLY_GRADED_TYPES))
            ->get();

        $schema = [];

        if ($autoGraded->isNotEmpty()) {
            $schema[] = Forms\Components\Section::make('Auto-graded answers')
                ->description('Already scored automatically — shown here for context only, not editable.')
                ->schema([
                    Forms\Components\Placeholder::make('auto_graded_summary')
                        ->label('')
                        ->content(new HtmlString(
                            '<div class="space-y-2">'.$autoGraded->map(function (QuizAnswer $answer) {
                                $icon = $answer->is_correct
                                    ? '<span class="text-success-600">✓</span>'
                                    : '<span class="text-danger-600">✕</span>';
                                $given = $answer->answer_text !== null && $answer->answer_text !== ''
                                    ? e($answer->answer_text)
                                    : '<span class="text-gray-400">No answer</span>';

                                return "<div class=\"text-sm\">{$icon} <strong>".e($answer->question->question_text).'</strong> — '
                                    ."answered: {$given} — {$answer->points_awarded} / {$answer->question->points} pts</div>";
                            })->implode('').'</div>'
                        )),
                ])
                ->collapsible();
        }

        if ($manualAnswers->isEmpty()) {
            $schema[] = Forms\Components\Placeholder::make('none')
                ->label('')
                ->content('This attempt has no essay, code or file-upload questions to grade — the auto-graded score above is final.');

            return $schema;
        }

        $manualSections = $manualAnswers->map(function (QuizAnswer $answer) {
            $question = $answer->question;

            return Forms\Components\Section::make($question->question_text)
                ->schema([
                    Forms\Components\Placeholder::make("preview_{$answer->id}")
                        ->label('Submitted Answer')
                        ->content(match ($question->type) {
                            'file_upload' => $answer->answer_text
                                ? new HtmlString('<a href="'.e(route('quiz-submissions.download', $answer)).'" class="underline">Download uploaded file</a>')
                                : 'No file submitted',
                            'code' => filled($answer->answer_text)
                                ? $this->codeBlock($question->answerFiles($answer->answer_text))
                                : 'No code submitted',
                            default => $answer->answer_text ?: '—',
                        }),

                    Forms\Components\Placeholder::make("database_{$answer->id}")
                        ->label('Student database (exported when submitted)')
                        ->content(fn () => match (true) {
                            $answer->hasVerifiedDatabaseSnapshot() => $this->codeBlock([['name' => "{$answer->database_name}.sql", 'content' => $answer->database_export]], canRun: false),
                            filled($answer->database_name) => 'Snapshot '.($answer->database_snapshot_status ?? 'pending').': the workspace is retained and writes are closed. Retry the snapshot before final grading or cleanup. A live read-only view is not the submitted evidence.',
                            default => 'The student never opened their database.',
                        })
                        ->hintAction(
                            Forms\Components\Actions\Action::make("open_database_{$answer->id}")
                                ->label('Open in phpMyAdmin')
                                ->icon('heroicon-o-arrow-top-right-on-square')
                                ->url(fn () => $answer->database_name ? route('student-databases.open', $answer) : null, shouldOpenInNewTab: true)
                                ->visible(fn () => filled($answer->database_name)),
                        )
                        ->visible($question->hasDatabase()),

                    Forms\Components\Placeholder::make("reference_{$answer->id}")
                        ->label('Your Reference Solution')
                        ->content(fn () => $this->codeBlock($question->referenceFiles()))
                        ->visible($question->type === 'code' && $question->referenceFiles() !== []),

                    Forms\Components\Placeholder::make("auto_check_{$answer->id}")
                        ->label('Automatic check')
                        ->content(fn () => $this->checkerBlock('single', [$question->id => $question->checkConfig()], [[
                            'id' => $answer->id,
                            'questionId' => $question->id,
                            'files' => filled($answer->answer_text) ? $question->answerFiles($answer->answer_text) : null,
                        ]], "mountedTableActionsData.0.awards.{$answer->id}.points"))
                        ->visible($question->autoChecks()),

                    Forms\Components\TextInput::make("awards.{$answer->id}.points")
                        ->label('Points Awarded')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue($answer->question->points)
                        ->default($answer->points_awarded)
                        ->helperText("Out of {$answer->question->points}")
                        ->required(),

                    Forms\Components\Textarea::make("awards.{$answer->id}.feedback")
                        ->label('Feedback')
                        ->default($answer->feedback)
                        ->rows(2),
                ]);
        })->toArray();

        return [...$schema, ...$manualSections];
    }

    /**
     * Read-only code with a Run button, so the instructor sees the page or
     * output the student's code produces, not only its source.
     *
     * @param  list<array{name: string, content: string}>  $files
     */
    protected function codeBlock(array $files, bool $canRun = true): HtmlString
    {
        return new HtmlString(Blade::render(
            '<x-code-editor :value="$files" read-only :can-run="$canRun" min-height="4rem" max-height="24rem" />',
            ['files' => $files, 'canRun' => $canRun],
        ));
    }

    /**
     * The in-browser checker (resources/js/components/code-checker.js).
     *
     * @param  array<int, array>  $questions  checkConfig() per question id
     * @param  list<array{id: int, questionId: int, files?: ?array, label?: string}>  $answers
     */
    protected function checkerBlock(string $mode, array $questions, array $answers, ?string $pointsPath = null): HtmlString
    {
        return new HtmlString(view('components.code-checker', compact('mode', 'questions', 'answers', 'pointsPath'))->render());
    }

    /**
     * Code answers of submitted attempts whose question is checked automatically.
     *
     * @return Collection<int, QuizAnswer>
     */
    protected function autoCheckedAnswers()
    {
        $questionIds = $this->getOwnerRecord()->questions()->where('type', 'code')->get()
            ->filter(fn (QuizQuestion $question) => $question->autoChecks())
            ->modelKeys();

        return QuizAnswer::query()
            ->whereIn('quiz_question_id', $questionIds ?: [0])
            ->whereHas('attempt', fn ($query) => $query->where('quiz_id', $this->getOwnerRecord()->id)->where('status', '!=', 'in_progress'))
            ->with(['question', 'attempt.user'])
            ->orderBy('quiz_attempt_id')
            ->get();
    }

    protected function checkedAnswer(int $answerId): QuizAnswer
    {
        abort_unless(auth()->user()?->can('update', $this->getOwnerRecord()), 403);
        $answer = QuizAnswer::with(['question', 'attempt'])->findOrFail($answerId);
        abort_unless($answer->attempt->quiz_id === $this->getOwnerRecord()->id && $answer->attempt->status !== 'in_progress' && $answer->question->autoChecks(), 403);

        return $answer;
    }

    /**
     * A submitted code answer's files for the checker, or null when the student left it empty.
     */
    #[Renderless]
    public function codeCheckFiles(int $answerId): ?array
    {
        $answer = $this->checkedAnswer($answerId);

        return filled($answer->answer_text) ? $answer->question->answerFiles($answer->answer_text) : null;
    }

    /**
     * Store the checker's test case results and the score they suggest. With
     * $apply, the suggested score becomes the answer's points unless the
     * instructor has given it other points by hand.
     *
     * @param  list<array{label: string, passed: bool, expected: string, actual: string}>  $cases
     * @return array{passed: int, total: int, suggested: float, applied: bool}
     */
    #[Renderless]
    public function recordAutoCheck(int $answerId, array $cases, bool $apply = false): array
    {
        $answer = $this->checkedAnswer($answerId);
        $cases = collect($cases)->take(QuizQuestion::MAX_CHECK_CASES)->map(fn ($case) => [
            'label' => mb_substr((string) ($case['label'] ?? ''), 0, 80),
            'passed' => (bool) ($case['passed'] ?? false),
            'expected' => mb_substr((string) ($case['expected'] ?? ''), 0, 2_000),
            'actual' => mb_substr((string) ($case['actual'] ?? ''), 0, 2_000),
        ])->values();
        $passed = $cases->where('passed', true)->count();
        $total = max(1, $cases->count());
        $suggested = round($answer->question->points * $passed / $total, 2);
        $previous = $answer->auto_check['applied_points'] ?? null;
        // Points the checker set earlier may be replaced; points set by hand are kept.
        $applies = $apply && ($answer->points_awarded === null || ($previous !== null && (float) $answer->points_awarded === (float) $previous));

        DB::transaction(function () use ($answer, $cases, $passed, $total, $suggested, $applies, $previous): void {
            $answer->update([
                'auto_check' => [
                    'passed' => $passed,
                    'total' => $total,
                    'suggested' => $suggested,
                    'cases' => $cases->all(),
                    'checked_at' => now()->toIso8601String(),
                    'applied_points' => $applies ? $suggested : $previous,
                ],
                ...($applies ? ['points_awarded' => $suggested, 'is_correct' => $suggested > 0] : []),
            ]);
            if ($applies) {
                $answer->attempt->recalculateScore();
            }
        });

        return ['passed' => $passed, 'total' => $total, 'suggested' => $suggested, 'applied' => $applies];
    }

    protected function saveGrades(QuizAttempt $record, array $data): void
    {
        abort_unless(auth()->user()->can('update', $this->getOwnerRecord()) && $record->quiz_id === $this->getOwnerRecord()->id && $record->status !== 'in_progress', 403);
        DB::transaction(function () use ($record, $data): void {
            SqliteWriter::reserve('quiz_attempts', $record->id);
            foreach ($data['awards'] ?? [] as $answerId => $award) {
                $answer = $record->answers()->whereKey($answerId)->whereHas('question', fn ($q) => $q->whereIn('type', QuizQuestion::MANUALLY_GRADED_TYPES))->firstOrFail();
                if ($answer->database_name && ! $answer->hasVerifiedDatabaseSnapshot()) {
                    throw ValidationException::withMessages(['awards' => 'Recover and verify the student database snapshot before assigning a final grade. The live workspace is retained.']);
                }
                validator($award, ['points' => ['required', 'numeric', 'min:0', 'max:'.$answer->question->points]])->validate();
                $answer->update([
                    'points_awarded' => $award['points'],
                    'feedback' => $award['feedback'] ?? null,
                    'is_correct' => (float) $award['points'] > 0,
                ]);
            }

            $record->recalculateScore();
        });

        Notification::make()
            ->title('Attempt graded')
            ->success()
            ->send();
    }
}
