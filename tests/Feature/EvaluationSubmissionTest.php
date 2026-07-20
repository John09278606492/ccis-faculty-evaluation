<?php

use App\Models\Campus;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\SchoolYear;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

function evaluationFixture(bool $enrolled = true): array
{
    Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    $role = Role::create(['name' => 'student', 'guard_name' => 'web']);
    $campus = Campus::create(['name' => 'Main Campus', 'slug' => 'main', 'is_active' => true]);
    $department = Department::create([
        'campus_id' => $campus->id,
        'name' => 'College of Computing',
        'code' => 'CCIS',
        'is_active' => true,
    ]);
    $schoolYear = SchoolYear::create([
        'campus_id' => $campus->id,
        'name' => '2026-2027',
        'start_date' => '2026-06-01',
        'end_date' => '2027-05-31',
        'is_current' => true,
    ]);
    $semester = Semester::create([
        'school_year_id' => $schoolYear->id,
        'name' => 'First Semester',
        'start_date' => '2026-06-01',
        'end_date' => '2026-12-31',
        'is_active' => true,
    ]);
    $course = Course::create([
        'department_id' => $department->id,
        'code' => 'CS101',
        'name' => 'Introduction to Computing',
        'is_active' => true,
    ]);
    $instructor = User::factory()->create([
        'is_instructor' => true,
        'instructor_department_id' => $department->id,
    ]);
    $form = Form::create([
        'name' => 'Faculty Evaluation',
        'slug' => 'faculty-evaluation',
        'is_active' => true,
        'requires_login' => true,
        'evaluator_roles' => ['student'],
        'rating_scale' => [
            ['value' => 5, 'label' => 'Excellent'],
            ['value' => 1, 'label' => 'Needs improvement'],
        ],
    ]);
    $form->campuses()->attach($campus);
    FormField::create([
        'form_id' => $form->id,
        'name' => 'teaching_quality',
        'label' => 'Teaching quality',
        'type' => 'likert_scale',
        'is_required' => true,
        'order' => 1,
    ]);
    $offering = CourseOffering::create([
        'semester_id' => $semester->id,
        'course_id' => $course->id,
        'user_id' => $instructor->id,
        'section' => 'A',
        'is_active' => true,
        'form_id' => $form->id,
    ]);
    $student = User::factory()->create(['is_student' => true]);
    $student->assignRole($role);
    $student->campuses()->attach($campus);

    if ($enrolled) {
        $offering->students()->attach($student, ['enrolled_at' => now()]);
    }

    return compact('student', 'offering', 'form');
}

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
        'is_published' => true,
        'assign_to_all' => true,
    ]);
    $question = QuizQuestion::create([
        'quiz_id' => $quiz->id,
        'question_text' => 'Upload your solution.',
        'type' => 'file_upload',
        'points' => 10,
        'order' => 1,
    ]);
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
