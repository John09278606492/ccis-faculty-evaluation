<?php

namespace App\Filament\Resources\QuizResource\RelationManagers;

use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class AttemptsRelationManager extends RelationManager
{
    use \App\Filament\Concerns\ReceivesClassroomUpdates;

    protected static string $relationship = 'attempts';

    protected static ?string $title = 'Attempts & Submissions';

    protected static ?string $recordTitleAttribute = 'id';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
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
            ])
            ->headerActions([
                ExportAction::make('exportResults')
                    ->label('Export results')->exports([
                        ExcelExport::make()
                            ->fromTable()->withFilename('quiz-'.$this->getOwnerRecord()->id.'-results-'.now()->format('Y-m-d')),
                    ]),
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
                                ? $this->codeBlock($answer->answer_text, $question->codeLanguage())
                                : 'No code submitted',
                            default => $answer->answer_text ?: '—',
                        }),

                    Forms\Components\Placeholder::make("reference_{$answer->id}")
                        ->label('Your Reference Solution')
                        ->content(fn () => $this->codeBlock($question->referenceSolution(), $question->codeLanguage()))
                        ->visible($question->type === 'code' && filled($question->referenceSolution())),

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

    protected function codeBlock(string $code, string $language): HtmlString
    {
        return new HtmlString(Blade::render(
            '<x-code-editor :value="$code" :language="$language" read-only min-height="4rem" max-height="24rem" />',
            ['code' => $code, 'language' => $language],
        ));
    }

    protected function saveGrades(QuizAttempt $record, array $data): void
    {
        abort_unless(auth()->user()->can('update', $this->getOwnerRecord()) && $record->quiz_id === $this->getOwnerRecord()->id && $record->status !== 'in_progress', 403);
        DB::transaction(function () use ($record, $data): void {
            foreach ($data['awards'] ?? [] as $answerId => $award) {
                $answer = $record->answers()->whereKey($answerId)->whereHas('question', fn ($q) => $q->whereIn('type', QuizQuestion::MANUALLY_GRADED_TYPES))->firstOrFail();
                validator($award, ['points' => ['required', 'numeric', 'min:0', 'max:'.$answer->question->points]])->validate();
                $answer->update([
                    'points_awarded' => $award['points'],
                    'feedback' => $award['feedback'] ?? null,
                    'is_correct' => (float) $award['points'] > 0,
                ]);
            }

            $record->update([
                'score' => $record->answers()->sum('points_awarded'),
                'status' => $record->hasUngradedAnswers() ? 'submitted' : 'graded',
            ]);
        });

        Notification::make()
            ->title('Attempt graded')
            ->success()
            ->send();
    }
}
