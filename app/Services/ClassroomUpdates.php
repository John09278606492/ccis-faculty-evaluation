<?php

namespace App\Services;

use App\Events\ClassroomChanged;
use App\Models\CourseOffering;
use App\Models\Quiz;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClassroomUpdates
{
    public static function quiz(int $quizId, string $kind): void
    {
        $courseId = Quiz::whereKey($quizId)->value('course_offering_id');
        if ($courseId) {
            self::course($courseId, $kind, $quizId);
        }
    }

    public static function course(?int $courseId, string $kind, ?int $quizId = null): void
    {
        if (! $courseId) {
            return;
        }
        DB::afterCommit(function () use ($courseId, $quizId, $kind) {
            try {
                $course = CourseOffering::with('semester.schoolYear')->find($courseId);
                if ($campusId = $course?->semester?->schoolYear?->campus_id) {
                    broadcast(new ClassroomChanged($campusId, $courseId, $quizId, $kind))->toOthers();
                }
            } catch (\Throwable $e) {
                // A disconnected WebSocket service must not lose an instructor's save.
                Log::warning('Live update could not be delivered', ['kind' => $kind, 'quiz_id' => $quizId, 'error' => $e->getMessage()]);
            }
        });
    }
}
