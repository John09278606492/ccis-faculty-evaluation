<?php

namespace App\Services;

use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Support\SqliteWriter;
use Illuminate\Support\Facades\DB;

/** The browser and the scheduled expiry sweep share one idempotent finalizer. */
class FinalizeAssessmentAttempt
{
    public function handle(QuizAttempt $attempt, string $reason = 'submitted'): QuizAttempt
    {
        $closed = DB::transaction(function () use ($attempt, $reason) {
            SqliteWriter::reserve('quiz_attempts', $attempt->id);
            $locked = QuizAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($locked->status !== 'in_progress') {
                return false;
            }
            $locked->recordFocusReturned();
            $score = 0;
            $manual = false;
            foreach ($locked->answers()->with('question')->get() as $answer) {
                $question = $answer->question;
                if ($question->isAutoGraded()) {
                    $correct = $question->grade($answer->answer_text) ?? false;
                    $points = $correct ? $question->points : 0;
                    $answer->update(['is_correct' => $correct, 'points_awarded' => $points]);
                    $score += $points;
                } elseif (in_array($question->type, QuizQuestion::MANUALLY_GRADED_TYPES, true)) {
                    $manual = true;
                }
                if ($answer->database_name && ! $answer->hasVerifiedDatabaseSnapshot()) {
                    $answer->update(['database_snapshot_status' => 'pending', 'database_snapshot_error' => null]);
                }
            }
            $expired = $locked->isExpired();
            $locked->update([
                'status' => $manual ? 'submitted' : 'graded', 'score' => $score,
                'submitted_at' => $expired && $locked->expires_at ? $locked->expires_at : now(),
                'finalized_reason' => $expired ? 'expired' : $reason,
            ]);

            return true;
        });
        $attempt->refresh();
        if ($closed || $attempt->status !== 'in_progress') {
            foreach ($attempt->answers()->whereNotNull('database_name')->get() as $answer) {
                app(StudentDatabase\DatabaseSnapshots::class)->capture($answer);
            }
        }

        return $attempt;
    }
}
