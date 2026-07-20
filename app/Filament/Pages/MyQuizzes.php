<?php

namespace App\Filament\Pages;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class MyQuizzes extends Page implements HasTable
{
    // Gated by the page_MyQuizzes permission (toggle per-role in Roles) instead
    // of just isStudent(), so access is actually controllable via permissions.
    use HasPageShield;

    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static string $view = 'filament.pages.my-quizzes';

    protected static ?string $navigationLabel = 'My Quizzes';

    protected static ?string $title = 'My Quizzes & Activities';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationGroup = 'Student';

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->columns([
                Tables\Columns\TextColumn::make('courseOffering.display_name')
                    ->label('Course')
                    ->searchable(),

                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Quiz::TYPES[$state] ?? $state),

                Tables\Columns\TextColumn::make('duration_minutes')
                    ->label('Duration')
                    ->formatStateUsing(fn (?int $state) => $state ? "{$state} min" : 'Untimed'),

                Tables\Columns\TextColumn::make('available_until')
                    ->label('Deadline')
                    ->dateTime('M j, Y g:ia')
                    ->placeholder('No deadline'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (Quiz $record) => $this->statusFor($record))
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        str_starts_with($state, 'Final score') => 'success',
                        str_starts_with($state, 'Partial score') => 'info',
                        str_starts_with($state, 'Starts') => 'warning',
                        $state === 'In Progress' => 'warning',
                        $state === 'Closed' => 'gray',
                        default => 'primary',
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label(fn (Quiz $record) => $this->actionLabelFor($record))
                    ->icon('heroicon-o-arrow-right-circle')
                    ->color('primary')
                    ->visible(fn (Quiz $record) => $this->actionLabelFor($record) !== null)
                    ->url(fn (Quiz $record) => TakeQuiz::getUrl(['quiz' => $record->id], panel: 'admin', tenant: $record->campus)),
            ])
            ->emptyStateHeading('No Quizzes Yet')
            ->emptyStateDescription('Your instructors have not published any quizzes, exams, or activities for your courses.')
            ->emptyStateIcon('heroicon-o-pencil-square');
    }

    protected function getTableQuery(): Builder
    {
        return Quiz::query()
            ->where('is_published', true)
            ->whereHas('courseOffering.students', function ($query) {
                $query->where('user_id', Auth::id());
            })
            // Enrollment alone isn't enough once an instructor restricts a quiz to
            // specific students — assign_to_all=false means only assignedStudents sees it.
            ->where(function ($query) {
                $query->where('assign_to_all', true)
                    ->orWhereHas('assignedStudents', fn ($q) => $q->where('users.id', Auth::id()));
            })
            ->with(['courseOffering.course', 'courseOffering.semester.schoolYear']);
    }

    /**
     * Memoized per quiz ID for this render — statusFor() and actionLabelFor()
     * (called up to 3x per row combined) would otherwise each re-query.
     */
    protected array $attemptCache = [];

    protected function latestAttempt(Quiz $quiz): ?QuizAttempt
    {
        if (! array_key_exists($quiz->id, $this->attemptCache)) {
            $this->attemptCache[$quiz->id] = $quiz->attempts()
                ->where('user_id', Auth::id())
                ->latest('attempt_number')
                ->first();
        }

        return $this->attemptCache[$quiz->id];
    }

    protected function statusFor(Quiz $quiz): string
    {
        $attempt = $this->latestAttempt($quiz);

        if (! $attempt) {
            if (! $quiz->hasWindowOpened()) {
                return 'Starts '.$quiz->available_from->format('M j, g:ia');
            }

            return $quiz->isAvailable() ? 'Not Started' : 'Closed';
        }

        return match ($attempt->status) {
            'in_progress' => 'In Progress',
            // Essay/file questions aren't graded yet, so this is a partial score, not the final one.
            'submitted' => "Partial score {$attempt->score} / {$attempt->max_score} — essay pending",
            'graded' => "Final score: {$attempt->score} / {$attempt->max_score}",
            default => $attempt->status,
        };
    }

    protected function actionLabelFor(Quiz $quiz): ?string
    {
        $attempt = $this->latestAttempt($quiz);

        if ($attempt?->status === 'in_progress') {
            return 'Continue';
        }

        if ($attempt) {
            $attemptsLeft = $attempt->attempt_number < $quiz->max_attempts;

            if ($attemptsLeft && $quiz->isAvailable()) {
                return 'Start New Attempt';
            }

            return 'Review';
        }

        if (! $quiz->hasWindowOpened()) {
            return 'View';
        }

        return $quiz->isAvailable() ? 'Start' : null;
    }
}
