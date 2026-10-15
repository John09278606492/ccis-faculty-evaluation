<?php

use App\Models\FormSubmission;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

it('blocks students who are not enrolled in the course offering', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture(false);

    $this->actingAs($student)
        ->get(route('evaluate.course-offering', $offering))
        ->assertForbidden();
});

it('validates exact fields and stores an anonymous submission once', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();

    $this->actingAs($student)
        ->from(route('evaluate.course-offering', $offering))
        ->post(route('evaluate.store', $offering), [
            'fields' => ['teaching_quality' => 4, 'injected' => 'not allowed'],
        ])
        ->assertSessionHasErrors('fields');

    expect(FormSubmission::count())->toBe(0);

    $payload = [
        'fields' => ['teaching_quality' => 5],
        'field_comments' => ['teaching_quality' => 'Clear explanations.'],
        'overall_comment' => 'Very effective instructor.',
    ];

    $this->actingAs($student)
        ->post(route('evaluate.store', $offering), $payload)
        ->assertRedirect(route('evaluate.success', $offering));

    $submission = FormSubmission::sole();
    expect($submission->user_id)->toBeNull()
        ->and($submission->ip_address)->toBeNull()
        ->and($submission->user_agent)->toBeNull()
        ->and($submission->submission_fingerprint)->toHaveLength(64)
        ->and($submission->data['teaching_quality'])->toBe(5);

    $this->actingAs($student)
        ->from(route('evaluate.course-offering', $offering))
        ->post(route('evaluate.store', $offering), $payload)
        ->assertSessionHas('error', 'You have already submitted this evaluation.');

    expect(FormSubmission::count())->toBe(1);
});

it('serves private quiz uploads only to the answer owner or quiz manager', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $quiz = Quiz::create([
        'course_offering_id' => $offering->id,
        'title' => 'Midterm',
        'type' => 'exam',
        'timing_mode' => 'duration',
        'max_attempts' => 1,
        'is_published' => false,
        'assign_to_all' => true,
    ]);
    $question = QuizQuestion::create([
        'quiz_id' => $quiz->id,
        'question_text' => 'Upload your solution.',
        'type' => 'file_upload',
        'points' => 10,
        'order' => 1,
    ]);
    $quiz->update(['is_published' => true]);
    $attempt = QuizAttempt::create([
        'quiz_id' => $quiz->id,
        'user_id' => $student->id,
        'attempt_number' => 1,
        'started_at' => now(),
        'status' => 'submitted',
        'max_score' => 10,
    ]);
    $answer = QuizAnswer::create([
        'quiz_attempt_id' => $attempt->id,
        'quiz_question_id' => $question->id,
        'answer_text' => "quiz-submissions/{$quiz->id}/solution.pdf",
    ]);
    Storage::fake('local');
    Storage::disk('local')->put($answer->answer_text, 'private answer');

    $this->actingAs($student)
        ->get(route('quiz-submissions.download', $answer))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertDownload('solution.pdf');

    $stranger = User::factory()->create();
    $this->actingAs($stranger)
        ->get(route('quiz-submissions.download', $answer))
        ->assertForbidden();
});
