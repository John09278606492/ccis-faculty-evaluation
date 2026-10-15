<?php

namespace App\Services;

use App\Models\ClassRecordSheet;
use App\Models\Quiz;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoSyncClassRecord
{
    public static function afterCommit(int $quizId): void
    {
        DB::afterCommit(fn () => app(self::class)->sync($quizId));
    }

    public function sync(int $quizId): void
    {
        // Grading has already committed. Workbook failures must never undo a grade.
        try {
            $quiz = Quiz::with('courseOffering')->find($quizId);
            if (! $quiz || ! $quiz->auto_sync_class_record || ! in_array($quiz->type, ['quiz', 'activity'], true)) {
                return;
            }
            if (! $quiz->attempts()->where('status', 'graded')->whereNotNull('score')->exists()) {
                return;
            }
            $sheets = ClassRecordSheet::where('course_offering_id', $quiz->course_offering_id)
                ->where('student_id_header', '!=', '')
                ->whereHas('upload', fn ($q) => $q->where(function ($owners) use ($quiz) {
                    $owners->where('uploaded_by', $quiz->courseOffering->user_id)
                        ->orWhereHas('uploader.roles', fn ($roles) => $roles->where('name', 'super_admin')->where('guard_name', 'web'));
                }))
                ->with(['quizColumns', 'upload'])->get();
            $linked = $sheets->filter(fn ($sheet) => $sheet->quizColumns->contains('quiz_id', $quizId));
            $destinations = $linked->isNotEmpty() ? $linked : $sheets;
            if ($destinations->count() !== 1) {
                throw new \RuntimeException($destinations->isEmpty()
                    ? 'Waiting for a class record: upload and map a worksheet owned by the course instructor or a super-admin.'
                    : 'Multiple worksheets match. Use Sync to Class Record to select the correct grading period first. Auto-sync requires one linked worksheet.');
            }
            app(ClassRecordSyncService::class)->sync($quiz, $destinations->first());
            Quiz::whereKey($quizId)->update(['class_record_synced_at' => now(), 'class_record_sync_error' => null]);
        } catch (\Throwable $e) {
            Log::warning('Class record auto-sync failed', ['quiz_id' => $quizId, 'reason' => $e->getMessage()]);
            try {
                Quiz::whereKey($quizId)->update(['class_record_sync_error' => mb_substr($e->getMessage(), 0, 2000)]);
            } catch (\Throwable $statusError) {
                report($statusError);
            }
        }
    }
}
