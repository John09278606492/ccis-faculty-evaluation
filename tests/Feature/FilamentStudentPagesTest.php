<?php

use App\Filament\Pages\MyEvaluationResults;
use App\Filament\Pages\MyEvaluations;
use App\Filament\Pages\MyQuizzes;
use App\Filament\Pages\TakeQuiz;
use App\Models\FormSubmission;
use App\Models\Quiz;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The pages a student sees: their evaluations, their assessments and the
 * assessment they are taking.
 */
beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function studentWith(string ...$permissions): User
{
    $student = User::factory()->create(['is_student' => true]);
    $student->assignRole(Role::findOrCreate('student', 'web'));
    foreach ($permissions as $permission) {
        $student->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $student;
}

it('lists the assessments a student may take', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $student->update(['student_id' => '2026-0001']);
    $student->givePermissionTo(Permission::findOrCreate('page_MyQuizzes', 'web'));
    Filament::setTenant($offering->campus, isQuiet: true);
    $quiz = Quiz::create([
        'course_offering_id' => $offering->id,
        'title' => 'PHP Basics',
        'type' => 'quiz',
        'duration_minutes' => 20,
        'max_attempts' => 1,
        'is_published' => false,
        'assign_to_all' => true,
    ]);
    $quiz->questions()->create(['type' => 'short_answer', 'question_text' => 'Name the output statement', 'points' => 10, 'order' => 1, 'correct_answer' => ['echo']]);
    $quiz->update(['is_published' => true]);
    $this->actingAs($student);

    Livewire::test(MyQuizzes::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$quiz])
        ->assertSee('PHP Basics');
});

it('keeps an assessment a student is not assigned to out of the list', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $student->update(['student_id' => '2026-0001']);
    $student->givePermissionTo(Permission::findOrCreate('page_MyQuizzes', 'web'));
    Filament::setTenant($offering->campus, isQuiet: true);
    $quiz = Quiz::create([
        'course_offering_id' => $offering->id,
        'title' => 'Only For Others',
        'type' => 'quiz',
        'duration_minutes' => 20,
        'max_attempts' => 1,
        'is_published' => false,
        'assign_to_all' => false,
    ]);
    $quiz->questions()->create(['type' => 'short_answer', 'question_text' => 'Name the output statement', 'points' => 10, 'order' => 1, 'correct_answer' => ['echo']]);
    $quiz->update(['is_published' => true]);
    $this->actingAs($student);

    Livewire::test(MyQuizzes::class)
        ->assertSuccessful()
        ->assertCanNotSeeTableRecords([$quiz]);
});

it('keeps a draft assessment out of the list', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $student->givePermissionTo(Permission::findOrCreate('page_MyQuizzes', 'web'));
    Filament::setTenant($offering->campus, isQuiet: true);
    $quiz = Quiz::create([
        'course_offering_id' => $offering->id,
        'title' => 'Still A Draft',
        'type' => 'quiz',
        'duration_minutes' => 20,
        'max_attempts' => 1,
        'is_published' => false,
        'assign_to_all' => true,
    ]);
    $this->actingAs($student);

    Livewire::test(MyQuizzes::class)
        ->assertSuccessful()
        ->assertCanNotSeeTableRecords([$quiz]);
});

it('lists the evaluations a student still owes', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $student->givePermissionTo(Permission::findOrCreate('page_MyEvaluations', 'web'));
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs($student);

    Livewire::test(MyEvaluations::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$offering])
        ->assertSee('Pending');
});

it('marks an evaluation the student already sent as completed', function () {
    ['student' => $student, 'offering' => $offering, 'form' => $form] = evaluationFixture();
    $student->givePermissionTo(Permission::findOrCreate('page_MyEvaluations', 'web'));
    Filament::setTenant($offering->campus, isQuiet: true);
    FormSubmission::create([
        'form_id' => $form->id,
        'course_offering_id' => $offering->id,
        'submission_fingerprint' => 'fp-done',
        'user_id' => $student->id,
        'data' => ['teaching_quality' => 5],
    ]);
    $this->actingAs($student);

    Livewire::test(MyEvaluations::class)
        ->assertSuccessful()
        ->assertSee('Completed');
});

it('sends a student away from the evaluations page without the permission', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs($student);

    Livewire::test(MyEvaluations::class)->assertForbidden();
});

it('sends a student away from the results page without the permission', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs($student);

    Livewire::test(MyEvaluationResults::class)->assertForbidden();
});

it('sends a student away from an assessment they are not enrolled in', function () {
    ['offering' => $offering] = evaluationFixture();
    $quiz = Quiz::create([
        'course_offering_id' => $offering->id,
        'title' => 'Not Yours',
        'type' => 'quiz',
        'duration_minutes' => 20,
        'max_attempts' => 1,
        'is_published' => false,
        'assign_to_all' => true,
    ]);
    $quiz->questions()->create(['type' => 'short_answer', 'question_text' => 'Name the output statement', 'points' => 10, 'order' => 1, 'correct_answer' => ['echo']]);
    $quiz->update(['is_published' => true]);
    $stranger = studentWith('page_MyQuizzes', 'page_TakeQuiz');
    $stranger->campuses()->attach($offering->campus->id);
    $this->actingAs($stranger);

    Livewire::test(TakeQuiz::class, ['quiz' => $quiz])
        ->assertSet('accessDenied', true)
        ->assertSee('not registered');
});

it('waits for the opening time instead of refusing a student', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $student->givePermissionTo(Permission::findOrCreate('page_MyQuizzes', 'web'));
    Filament::setTenant($offering->campus, isQuiet: true);
    $quiz = Quiz::create([
        'course_offering_id' => $offering->id,
        'title' => 'Later Today',
        'type' => 'quiz',
        'duration_minutes' => 20,
        'max_attempts' => 1,
        'is_published' => false,
        'assign_to_all' => true,
        'available_from' => now()->addHour(),
    ]);
    $quiz->questions()->create(['type' => 'short_answer', 'question_text' => 'Name the output statement', 'points' => 10, 'order' => 1, 'correct_answer' => ['echo']]);
    $quiz->update(['is_published' => true]);
    $this->actingAs($student);

    Livewire::test(TakeQuiz::class, ['quiz' => $quiz])
        ->assertSet('waitingForStart', true)
        ->assertSet('accessDenied', false)
        ->assertSet('readyToStart', false);
});

it('refuses a student the assessment page without the permission', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $quiz = Quiz::create([
        'course_offering_id' => $offering->id,
        'title' => 'PHP Basics',
        'type' => 'quiz',
        'duration_minutes' => 20,
        'max_attempts' => 1,
        'is_published' => false,
        'assign_to_all' => true,
    ]);
    $quiz->questions()->create(['type' => 'short_answer', 'question_text' => 'Name the output statement', 'points' => 10, 'order' => 1, 'correct_answer' => ['echo']]);
    $quiz->update(['is_published' => true]);
    $this->actingAs($student);

    Livewire::test(TakeQuiz::class, ['quiz' => $quiz])->assertForbidden();
});
