<?php

namespace App\Services;

use App\Models\Quiz;
use App\Services\StudentDatabase\StudentDatabases;

class AssessmentReadiness
{
    public function forQuiz(Quiz $quiz): array
    {
        $issues = $quiz->publicationIssues();
        $participants = $quiz->students()->when(! $quiz->assign_to_all, fn ($q) => $q->whereIn('users.id', $quiz->assignedStudents()->select('users.id')))->count();
        $needsDatabase = $quiz->questions()->get()->contains(fn ($question) => $question->hasDatabase());
        $databaseReady = ! $needsDatabase || app(StudentDatabases::class)->available();

        return [
            ['label' => 'Questions', 'ready' => $issues === [], 'detail' => $issues === [] ? $quiz->questions()->count().' questions · '.$quiz->total_points.' points' : implode(' ', $issues)],
            ['label' => 'Participants', 'ready' => $participants > 0, 'detail' => $participants.' eligible students. Use Participants & conduct to assign or review them.'],
            ['label' => 'Schedule', 'ready' => $quiz->type === 'activity' || ($quiz->timing_mode === 'scheduled' ? $quiz->available_from && $quiz->available_until : $quiz->duration_minutes > 0),
                'detail' => $quiz->timingLabel().($quiz->available_until ? ' · closes '.$quiz->available_until->format('M j, Y g:i A') : ' · no closing date')],
            ['label' => 'Database workspace', 'ready' => $databaseReady, 'detail' => ! $needsDatabase ? 'Not required.' : ($databaseReady ? 'Student database service is available.' : 'Start or recover the student database service before students begin.')],
        ];
    }
}
