<?php

namespace App\Services;

use App\Models\BackgroundTask;
use App\Models\Campus;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AssessmentBulkFiles
{
    public function quiz(User $actor, Campus $campus, int $id, bool $pins = false): Quiz
    {
        $quiz = app(AssignedDataAccess::class)->apply(Quiz::query(), $actor, $campus)->findOrFail($id);
        abort_unless($actor->can($pins ? 'update' : 'view', $quiz), 403);

        return $quiz;
    }

    public function generate(BackgroundTask $task, User $actor, Campus $campus): array
    {
        $tasks = app(BackgroundTasks::class);
        $quiz = $this->quiz($actor, $campus, $task->payload['quiz_id'], $task->kind === 'assessment-pins');
        $ids = array_values(array_unique(array_map('intval', $task->payload['ids'])));
        if ($task->kind === 'assessment-export') {
            $query = $quiz->attempts()->whereKey($ids)->with('user');
            abort_unless((clone $query)->count() === count($ids), 403);
            $tasks->csv($task, 'assessment-results.csv', function ($handle) use ($tasks, $query) {
                $tasks->row($handle, ['Student ID', 'Student', 'Attempt', 'Status', 'Score', 'Maximum', 'Submitted At']);
                $query->chunkById(100, function ($attempts) use ($tasks, $handle) {
                    foreach ($attempts as $attempt) {
                        $tasks->row($handle, [$attempt->user?->student_id, $attempt->user?->name, $attempt->attempt_number, $attempt->status, $attempt->score, $attempt->max_score, $attempt->submitted_at]);
                    }
                });
            });

            return ['scope_ids' => $ids, 'row_count' => count($ids), 'message' => count($ids).' assessment results exported. Your private CSV download is ready.'];
        }

        return DB::transaction(function () use ($task, $quiz, $ids, $tasks) {
            if (DB::getDriverName() === 'sqlite') {
                DB::table('quizzes')->where('id', $quiz->id)->update(['id' => $quiz->id]);
            }
            $students = $quiz->students()->whereIn('users.id', $ids)->where('is_student', true)->lockForUpdate()->get();
            abort_unless($students->count() === count($ids), 403);
            $issued = StudentPins::issue($students->whereNull('pin_issued_at'));
            $handle = fopen('php://temp', 'w+');
            try {
                $tasks->row($handle, ['Student ID', 'Name', 'PIN']);
                foreach ($issued as $row) {
                    $tasks->row($handle, [$row['student']->student_id, $row['student']->name, $row['pin']]);
                }
                rewind($handle);
                $path = 'background-tasks/'.$task->id.'/result.enc';
                abort_unless(Storage::disk('local')->put($path, Crypt::encryptString(stream_get_contents($handle))), 500);
                $task->update(['file_path' => $path, 'filename' => 'assessment-student-pins.csv']);
            } finally {
                fclose($handle);
            }

            return ['scope_ids' => $ids, 'encrypted' => true, 'row_count' => $issued->count(), 'message' => $issued->count().' missing PINs generated. Existing PINs were preserved. Download and share them privately.'];
        });
    }

    public function authorizeDownload(BackgroundTask $task, User $actor, Campus $campus): void
    {
        $quiz = $this->quiz($actor, $campus, $task->payload['quiz_id'], $task->kind === 'assessment-pins');
        $ids = $task->result['scope_ids'] ?? [];
        $query = $task->kind === 'assessment-pins' ? $quiz->students()->whereIn('users.id', $ids) : $quiz->attempts()->whereKey($ids);
        abort_unless($query->count() === count($ids), 403);
    }
}
