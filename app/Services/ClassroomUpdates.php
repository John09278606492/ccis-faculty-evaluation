<?php

namespace App\Services;

use App\Events\ClassroomChanged;
use App\Events\PanelDataChanged;
use App\Models\CourseOffering;
use App\Models\Quiz;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClassroomUpdates
{
    public static function viewer(int $userId, string $kind): void
    {
        DB::afterCommit(function () use ($userId, $kind) {
            try {
                broadcast(new PanelDataChanged($kind, viewerId: $userId));
            } catch (\Throwable $e) {
                Log::warning('Private live update could not be delivered', ['kind' => $kind, 'error' => $e->getMessage()]);
            }
        });
    }

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

    /** @var array<string, bool> kind => staff only, waiting to be sent when the request ends */
    private static array $pendingPanel = [];

    /**
     * Tells open panel pages that shared records (evaluations, setup data, users, posts)
     * changed so their tables and widgets reload. Many saves in one request, such as a
     * seeder or an import, send one message per kind when the request ends.
     */
    public static function panel(string $kind, bool $staffOnly = false): void
    {
        DB::afterCommit(function () use ($kind, $staffOnly) {
            if (self::$pendingPanel === []) {
                app()->terminating(fn () => self::sendPanel());
            }
            self::$pendingPanel[$kind] = (self::$pendingPanel[$kind] ?? true) && $staffOnly;
        });
    }

    public static function sendPanel(): void
    {
        $pending = self::$pendingPanel;
        self::$pendingPanel = [];
        foreach ($pending as $kind => $staffOnly) {
            try {
                // The saving browser also has sibling widgets/context selectors to update.
                broadcast(new PanelDataChanged($kind, $staffOnly));
            } catch (\Throwable $e) {
                Log::warning('Live update could not be delivered', ['kind' => $kind, 'error' => $e->getMessage()]);
            }
        }
    }
}
