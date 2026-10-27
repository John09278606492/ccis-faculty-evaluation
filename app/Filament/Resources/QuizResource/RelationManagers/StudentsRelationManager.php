<?php

namespace App\Filament\Resources\QuizResource\RelationManagers;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\BackgroundTasks;
use App\Services\StudentPins;
use App\Services\StudentSignIns;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StudentsRelationManager extends RelationManager
{
    protected static string $relationship = 'students';

    protected static ?string $title = 'Students & sign-in';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Quiz && ! auth()->user()?->is_student
            && (auth()->user()?->can('update', $ownerRecord) ?? false);
    }

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
            ->poll('10s')
            ->heading('Students, assignment & sign-in')
            ->description('1. Assign students. 2. Ask them to enter their ID on the login page. 3. Approve one or select several to sign them in. PINs are an alternative. Approval does not start their attempt or change the schedule.')
            ->columns([
                Tables\Columns\TextColumn::make('waiting_sign_ins')->label('Waiting sign-ins')
                    ->state(fn (User $record) => StudentSignIns::pendingCount($record->id))->badge()->color('warning'),
                Tables\Columns\IconColumn::make('pin_issued_at')->label('PIN issued')->boolean()
                    ->getStateUsing(fn (User $record) => filled($record->pin_issued_at)),
                Tables\Columns\TextColumn::make('student_id')
                    ->label('Student ID')
                    ->searchable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Student')
                    ->searchable(['first_name', 'last_name']),

                Tables\Columns\ToggleColumn::make('assigned')
                    ->label('Can Take It')
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
                Tables\Filters\Filter::make('waiting_sign_in')->label('Waiting for sign-in approval')->toggle()
                    ->query(fn (Builder $query) => $query->whereIn('users.id', DB::table('student_sign_in_requests')->select('user_id')
                        ->whereNull('approved_at')->whereNull('consumed_at')->where('expires_at', '>', now()))),
                Tables\Filters\Filter::make('not_attempted')
                    ->label('Has not attempted yet')
                    ->query(fn (Builder $query) => $query->whereDoesntHave('quizAttempts', fn ($q) => $q->where('quiz_id', $this->getQuiz()->id)))
                    ->toggle(),
                Tables\Filters\Filter::make('not_assigned')
                    ->label('Not assigned')
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
                // One click for a whole room, without selecting rows first.
                Tables\Actions\Action::make('approveAllWaiting')->label('Approve waiting sign-ins')->icon('heroicon-o-check-badge')
                    ->badge(fn () => count($this->waitingRequestOptions()) ?: null)
                    ->visible(fn () => auth()->user()->can('update', $this->getQuiz()))
                    ->modalDescription('Every assigned student waiting on the login page is listed with the code shown on their screen. All are ticked; untick any you do not recognise. A student with two waiting browsers is left unticked so you can pick the right one.')
                    ->modalSubmitActionLabel('Approve ticked')
                    ->form([
                        Forms\Components\CheckboxList::make('request_ids')->label('Waiting browsers')->required()->bulkToggleable()
                            ->options(fn () => $this->waitingRequestOptions())
                            ->default(fn () => StudentSignIns::unambiguousRequests($this->waitingStudents()))
                            ->helperText('Nobody listed? Ask the students to enter their ID on the login page, then open this again.'),
                    ])
                    ->action(function (array $data) {
                        abort_unless(auth()->user()->can('update', $this->getQuiz()), 403);
                        $count = StudentSignIns::approve($this->waitingStudents(), assessment: $this->getQuiz(), requestIds: $data['request_ids']);
                        Notification::make()->title("{$count} waiting sign-in request(s) approved")->success()->send();
                    }),
                Tables\Actions\Action::make('assign_to_all')
                    ->label('Assign to All Students')
                    ->icon('heroicon-o-users')
                    ->color('gray')
                    ->visible(fn () => ! $this->getQuiz()->assign_to_all)
                    ->requiresConfirmation()
                    ->modalDescription('Every student enrolled in this course offering will be able to take this assessment.')
                    ->action(function () {
                        $quiz = $this->getQuiz();
                        $quiz->update(['assign_to_all' => true]);
                        $quiz->assignedStudents()->detach();

                        Notification::make()->title('Assigned to all students')->success()->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('approveSignIn')->label('Approve sign-in')->icon('heroicon-o-check-circle')
                    ->visible(fn (User $record) => $record->is_student && $this->getQuiz()->isAssignedTo($record->id) && auth()->user()->can('update', $this->getQuiz()))
                    ->requiresConfirmation()
                    ->modalDescription('Compare the code shown on the student’s device, then approve only that waiting browser. They still click Start when available.')
                    ->form([
                        Forms\Components\Select::make('request_ids')->label('Waiting browser verification code')->multiple()->required()
                            ->options(fn (User $record) => StudentSignIns::requestOptions(collect([$record])))
                            ->default(fn (User $record) => StudentSignIns::unambiguousRequests(collect([$record]))),
                    ])
                    ->action(function (User $record, array $data) {
                        $count = StudentSignIns::approve(collect([$record]), assessment: $this->getQuiz(), requestIds: $data['request_ids']);
                        Notification::make()->title("{$count} waiting sign-in request(s) approved")->success()->send();
                    }),
                Tables\Actions\Action::make('newPin')->label('Reset account PIN')->icon('heroicon-o-key')->color('gray')
                    ->visible(fn (User $record) => $record->is_student && auth()->user()->can('update', $this->getQuiz()))
                    ->requiresConfirmation()->modalDescription('Replace this student’s account PIN? Their previous PIN will stop working across all classes.')
                    ->action(function (User $record) {
                        abort_unless(auth()->user()->can('update', $this->getQuiz()), 403);
                        $pin = StudentPins::issue(collect([$record]))->first()['pin'];
                        Notification::make()->title("New PIN: {$pin}")->body('Share it privately with this student. It will not be shown again.')->success()->persistent()->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('approveSignIns')->label('Approve selected sign-ins')->icon('heroicon-o-check-circle')
                        ->visible(fn () => auth()->user()->can('update', $this->getQuiz()))
                        ->requiresConfirmation()->deselectRecordsAfterCompletion()
                        ->modalDescription('Match browser verification codes with the students. Only selected requests are approved; other browsers remain waiting.')
                        ->form([
                            Forms\Components\CheckboxList::make('request_ids')->label('Verified waiting browsers')->required()
                                ->options(fn (Collection $records) => StudentSignIns::requestOptions($records->filter(fn ($student) => $this->getQuiz()->isAssignedTo($student->id))))
                                ->default(fn (Collection $records) => StudentSignIns::unambiguousRequests($records->filter(fn ($student) => $this->getQuiz()->isAssignedTo($student->id)))),
                        ])
                        ->action(function (Collection $records, array $data) {
                            abort_unless(auth()->user()->can('update', $this->getQuiz()), 403);
                            $eligible = $records->filter(fn (User $student) => $student->is_student && $this->getQuiz()->isAssignedTo($student->id));
                            $count = StudentSignIns::approve($eligible, assessment: $this->getQuiz(), requestIds: $data['request_ids']);
                            Notification::make()->title("{$count} waiting sign-in request(s) approved")
                                ->body(($records->count() - $eligible->count()).' unassigned or non-student account(s) skipped.')->success()->send();
                        }),
                    Tables\Actions\BulkAction::make('issueMissingPins')->label('Generate missing PINs for selected')->icon('heroicon-o-key')
                        ->visible(fn () => auth()->user()->can('update', $this->getQuiz()))
                        ->requiresConfirmation()->deselectRecordsAfterCompletion()
                        ->modalDescription('Generate and download account PINs only for selected students without one. Existing PINs are preserved.')
                        ->action(function (Collection $records) {
                            abort_unless(auth()->user()->can('update', $this->getQuiz()), 403);
                            app(BackgroundTasks::class)->enqueue(auth()->user(), filament()->getTenant(), 'assessment-pins', 'Generate selected missing PINs', ['quiz_id' => $this->getQuiz()->id, 'ids' => $records->pluck('id')->all()]);
                        }),
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

    /** Assigned students of this assessment who have a sign-in request waiting. */
    protected function waitingStudents(): Collection
    {
        $waiting = DB::table('student_sign_in_requests')->whereNull('approved_at')->whereNull('consumed_at')
            ->where('expires_at', '>', now())->pluck('user_id')->unique();

        return $this->getQuiz()->courseOffering->students()->whereIn('users.id', $waiting)->where('users.is_student', true)->get()
            ->filter(fn (User $student) => $this->getQuiz()->isAssignedTo($student->id))->values();
    }

    protected function waitingRequestOptions(): array
    {
        return StudentSignIns::requestOptions($this->waitingStudents());
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
                ->body('The selected student(s) have already attempted this assessment, so their assignment is locked.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title($assign ? 'Student(s) assigned' : 'Student(s) unassigned')
            ->body(! empty($lockedIds)
                ? count($lockedIds).' student(s) were skipped because they already attempted this assessment.'
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
