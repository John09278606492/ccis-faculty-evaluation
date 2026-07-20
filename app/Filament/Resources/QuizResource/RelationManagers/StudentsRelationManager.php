<?php

namespace App\Filament\Resources\QuizResource\RelationManagers;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class StudentsRelationManager extends RelationManager
{
    protected static string $relationship = 'students';

    protected static ?string $title = 'Students';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Latest attempt per user_id for this quiz, loaded once per page render
     * instead of once per row — a roster of 40 students would otherwise fire
     * dozens of near-identical queries just to paint the Status column.
     */
    protected ?Collection $latestAttemptsByUser = null;

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        // Loaded once here so every isAssignedTo() call during this render reuses
        // this in-memory relation instead of firing its own query per row.
        $this->getQuiz()->loadMissing('assignedStudents');

        return $table
            ->heading('Students & Eligibility')
            ->description('Control who this quiz is assigned to, and see who has not attempted it yet.')
            ->columns([
                Tables\Columns\TextColumn::make('student_id')
                    ->label('Student ID')
                    ->searchable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Student')
                    ->searchable(['first_name', 'last_name']),

                Tables\Columns\ToggleColumn::make('assigned')
                    ->label('Can Take Quiz')
                    ->getStateUsing(fn (User $record) => $this->getQuiz()->isAssignedTo($record->id))
                    ->updateStateUsing(fn (User $record, $state) => $this->setAssignment([$record->id], (bool) $state))
                    // Once a student has started/submitted an attempt, their assignment
                    // is locked — flipping it afterward would orphan or fabricate an
                    // attempt against a student who (un)officially couldn't take it.
                    ->disabled(fn (User $record) => $this->hasAttempted($record->id))
                    ->tooltip(fn (User $record) => $this->hasAttempted($record->id)
                        ? 'Already attempted — assignment is locked for this student.'
                        : null),

                Tables\Columns\TextColumn::make('attempt_status')
                    ->label('Status')
                    ->state(fn (User $record) => $this->statusFor($record))
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        str_starts_with($state, 'Graded') => 'success',
                        str_starts_with($state, 'Submitted') => 'info',
                        $state === 'In Progress' => 'warning',
                        $state === 'Not Assigned' => 'gray',
                        default => 'danger',
                    }),
            ])
            ->filters([
                Tables\Filters\Filter::make('not_attempted')
                    ->label('Has not attempted yet')
                    ->query(fn (Builder $query) => $query->whereDoesntHave('quizAttempts', fn ($q) => $q->where('quiz_id', $this->getQuiz()->id)))
                    ->toggle(),
                Tables\Filters\Filter::make('not_assigned')
                    ->label('Not assigned this quiz')
                    ->query(function (Builder $query) {
                        if ($this->getQuiz()->assign_to_all) {
                            // Everyone's assigned — nothing matches.
                            return $query->whereRaw('1 = 0');
                        }

                        return $query->whereDoesntHave('assignedQuizzes', fn ($q) => $q->where('quizzes.id', $this->getQuiz()->id));
                    })
                    ->toggle(),
            ], layout: FiltersLayout::AboveContent)
            ->headerActions([
                Tables\Actions\Action::make('assign_to_all')
                    ->label('Assign to All Students')
                    ->icon('heroicon-o-users')
                    ->color('gray')
                    ->visible(fn () => ! $this->getQuiz()->assign_to_all)
                    ->requiresConfirmation()
                    ->modalDescription('Every student enrolled in this course offering will be able to take this quiz.')
                    ->action(function () {
                        $quiz = $this->getQuiz();
                        $quiz->update(['assign_to_all' => true]);
                        $quiz->assignedStudents()->detach();

                        Notification::make()->title('Assigned to all students')->success()->send();
                    }),
            ])
            ->actions([])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('assign')
                        ->label('Assign Selected')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => $this->setAssignment($records->pluck('id')->all(), true)),

                    Tables\Actions\BulkAction::make('unassign')
                        ->label('Unassign Selected')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => $this->setAssignment($records->pluck('id')->all(), false)),
                ]),
            ])
            ->defaultSort('last_name');
    }

    protected function getQuiz(): Quiz
    {
        return $this->getOwnerRecord();
    }

    /**
     * Switches specific students on/off the assignment list. The first time this
     * runs on a quiz that's still in "assign to all" mode, the current full roster
     * is snapshotted into the pivot first so everyone else keeps access — only then
     * is the requested change (assign or unassign) applied on top of that snapshot.
     *
     * Students who already have an attempt are skipped entirely — their assignment
     * is frozen at whatever it was when they started, whether this call came from
     * the per-row toggle (already disabled client-side) or a bulk action (not).
     */
    protected function setAssignment(array $userIds, bool $assign): void
    {
        $quiz = $this->getQuiz();

        $lockedIds = array_values(array_filter($userIds, fn ($id) => $this->hasAttempted($id)));
        $userIds = array_values(array_diff($userIds, $lockedIds));

        if (! empty($userIds)) {
            if ($quiz->assign_to_all) {
                $rosterIds = $quiz->students()->pluck('users.id')->all();
                $quiz->update(['assign_to_all' => false]);
                $quiz->assignedStudents()->sync($rosterIds);
            }

            if ($assign) {
                $quiz->assignedStudents()->syncWithoutDetaching($userIds);
            } else {
                $quiz->assignedStudents()->detach($userIds);
            }
        }

        if (! empty($lockedIds) && empty($userIds)) {
            Notification::make()
                ->title('No changes made')
                ->body('The selected student(s) have already attempted this quiz, so their assignment is locked.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title($assign ? 'Student(s) assigned' : 'Student(s) unassigned')
            ->body(! empty($lockedIds)
                ? count($lockedIds).' student(s) were skipped because they already attempted this quiz.'
                : null)
            ->success()
            ->send();
    }

    /**
     * All attempts for this quiz, fetched once and reduced to the latest attempt
     * per student — reused by both hasAttempted() and statusFor() so rendering
     * the roster never queries per row.
     */
    protected function latestAttemptsByUser(): Collection
    {
        if ($this->latestAttemptsByUser === null) {
            $this->latestAttemptsByUser = QuizAttempt::where('quiz_id', $this->getQuiz()->id)
                ->orderByDesc('attempt_number')
                ->get()
                ->unique('user_id')
                ->keyBy('user_id');
        }

        return $this->latestAttemptsByUser;
    }

    /**
     * Whether this student has an attempt of any status (in progress, submitted,
     * or graded) for this quiz — once true, their assignment can no longer change.
     */
    protected function hasAttempted(int $userId): bool
    {
        return $this->latestAttemptsByUser()->has($userId);
    }

    protected function statusFor(User $student): string
    {
        if (! $this->getQuiz()->isAssignedTo($student->id)) {
            return 'Not Assigned';
        }

        $attempt = $this->latestAttemptsByUser()->get($student->id);

        if (! $attempt) {
            return 'Not Started';
        }

        return match ($attempt->status) {
            'in_progress' => 'In Progress',
            'submitted' => "Submitted {$attempt->score}/{$attempt->max_score} — pending grade",
            'graded' => "Graded {$attempt->score}/{$attempt->max_score}",
            default => $attempt->status,
        };
    }
}
