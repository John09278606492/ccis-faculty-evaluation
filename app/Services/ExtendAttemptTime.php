<?php

namespace App\Services;

use App\Models\QuizAttempt;
use App\Support\SqliteWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Gives a student more time on an unfinished attempt. Saved answers are kept. An attempt
 * that was closed only because its time ran out is reopened, so the student can continue it.
 */
class ExtendAttemptTime
{
    /** Attempts that can still be continued: open ones and ones closed by the time limit, latest per student. */
    public function eligible(int $quizId, bool $includeTimedOut = true): Builder
    {
        return QuizAttempt::where('quiz_id', $quizId)
            ->where(fn ($q) => $q->where('status', 'in_progress')
                ->when($includeTimedOut, fn ($q) => $q->orWhere('finalized_reason', 'expired')))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('quiz_attempts as later')
                ->whereColumn('later.quiz_id', 'quiz_attempts.quiz_id')
                ->whereColumn('later.user_id', 'quiz_attempts.user_id')
                ->whereColumn('later.attempt_number', '>', 'quiz_attempts.attempt_number'));
    }

    public function canExtend(QuizAttempt $attempt): bool
    {
        return $this->eligible($attempt->quiz_id)->whereKey($attempt->id)->exists();
    }

    /** New end time = now + minutes (or the current end + minutes when it is still in the future). */
    public function extend(QuizAttempt $attempt, int $minutes, bool $anyDevice): bool
    {
        $minutes = max(1, min(60 * 24 * 14, $minutes));

        return DB::transaction(function () use ($attempt, $minutes, $anyDevice) {
            SqliteWriter::reserve('quiz_attempts', $attempt->id);
            $locked = QuizAttempt::query()->lockForUpdate()->find($attempt->id);
            if (! $locked || ! $this->canExtend($locked)) {
                return false;
            }
            $from = $locked->status === 'in_progress' && $locked->expires_at && $locked->expires_at->isFuture() ? $locked->expires_at : now();
            $changes = ['expires_at' => $from->copy()->addMinutes($minutes)];
            if ($locked->status !== 'in_progress') {
                $changes += ['status' => 'in_progress', 'submitted_at' => null, 'score' => null, 'finalized_reason' => null];
            }
            if ($anyDevice) {
                // The next device that opens the exam becomes its device (e.g. the student's computer at home).
                $changes += ['device_hash' => null, 'blocked_device_hash' => null];
            }
            $locked->update($changes);

            return true;
        });
    }
}
