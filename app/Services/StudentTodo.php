<?php

namespace App\Services;

use App\Filament\Pages\MyAcknowledgements;
use App\Filament\Pages\TakeQuiz;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Support\Collection;

/** What a student should do now, for the top of their dashboard: open assessments and receipts to sign. */
class StudentTodo
{
    /** @return array{assessments: Collection, receipts: Collection, advising: Collection, total: int} */
    public function for(User $student, ?int $campusId): array
    {
        $assessments = $this->assessments($student, $campusId);
        $receipts = MyAcknowledgements::canAccess()
            ? app(AcknowledgementForms::class)->studentSummary($student, $campusId)->where('to_sign', '>', 0)->values()
                ->map(fn ($row) => [
                    'count' => (int) $row->to_sign,
                    'subject' => trim($row->code.' · '.$row->name),
                    'section' => $row->section,
                    'url' => MyAcknowledgements::getUrl(['subject' => $row->id]),
                ])
            : collect();

        $advising = \App\Filament\Pages\MyAdvising::canAccess()
            ? app(Advising::class)->toDo($student, $campusId)->with('advisoryClass.section')->orderBy('due_at')->get()
            : collect();

        return ['assessments' => $assessments, 'receipts' => $receipts, 'advising' => $advising, 'total' => $assessments->count() + $receipts->sum('count') + $advising->count()];
    }

    /** Assessments open right now that the student can start or must finish, soonest deadline first. */
    protected function assessments(User $student, ?int $campusId): Collection
    {
        $quizzes = Quiz::query()
            ->where('is_published', true)
            ->whereHas('courseOffering.semester.schoolYear', fn ($q) => $q->where('campus_id', $campusId))
            ->whereHas('courseOffering.students', fn ($q) => $q->where('user_id', $student->id))
            ->where(fn ($q) => $q->where('assign_to_all', true)->orWhereHas('assignedStudents', fn ($q) => $q->where('users.id', $student->id)))
            ->with(['courseOffering.course', 'courseOffering.semester.schoolYear.campus'])
            ->get()
            ->filter(fn (Quiz $quiz) => $quiz->isAvailable());

        // Ascending, so keyBy keeps each quiz's latest attempt.
        $latest = QuizAttempt::where('user_id', $student->id)->whereIn('quiz_id', $quizzes->modelKeys())
            ->orderBy('attempt_number')->get()->keyBy('quiz_id');

        return $quizzes
            ->map(function (Quiz $quiz) use ($latest) {
                $attempt = $latest[$quiz->id] ?? null;
                $action = match (true) {
                    $attempt === null => 'Start',
                    $attempt->status === 'in_progress' && ! $attempt->isExpired() => 'Continue',
                    default => null,
                };

                return $action ? [
                    'title' => $quiz->title,
                    'type' => Quiz::TYPES[$quiz->type] ?? $quiz->type,
                    'subject' => $quiz->courseOffering?->course?->code,
                    'due' => $quiz->available_until,
                    'action' => $action,
                    'url' => TakeQuiz::getUrl(['quiz' => $quiz->id], panel: 'admin', tenant: $quiz->campus),
                ] : null;
            })
            ->filter()
            ->sortBy(fn ($item) => [$item['action'] === 'Continue' ? 0 : 1, $item['due']?->timestamp ?? PHP_INT_MAX])
            ->values();
    }
}
