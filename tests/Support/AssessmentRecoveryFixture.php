<?php

use App\Models\Quiz;
use App\Models\QuizAttempt;

function recoveryAssessment(): array
{
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $quiz = Quiz::create(['course_offering_id' => $offering->id, 'title' => 'Recovery', 'type' => 'exam', 'auto_sync_class_record' => false, 'duration_minutes' => 10, 'max_attempts' => 1]);
    $question = $quiz->questions()->create(['type' => 'short_answer', 'question_text' => 'Answer', 'correct_answer' => ['yes'], 'points' => 5, 'order' => 1]);
    $code = $quiz->questions()->create(['type' => 'code', 'question_text' => 'SQL evidence', 'points' => 5, 'order' => 2, 'options' => ['language' => 'sql', 'database' => ['enabled' => true]]]);
    $attempt = QuizAttempt::create(['quiz_id' => $quiz->id, 'user_id' => $student->id, 'attempt_number' => 1, 'status' => 'in_progress', 'started_at' => now()->subMinutes(11), 'expires_at' => now()->subMinute(), 'max_score' => 10]);
    $attempt->answers()->create(['quiz_question_id' => $question->id, 'answer_text' => 'yes']);
    $answer = $attempt->answers()->create(['quiz_question_id' => $code->id, 'answer_text' => '{}', 'database_name' => "stu{$student->id}_a{$attempt->id}_q{$code->id}"]);

    return compact('attempt', 'answer', 'student');
}
