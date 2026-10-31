<?php

use App\Http\Middleware\EnsureCampusAccess;
use App\Models\Campus;
use App\Models\FormSubmission;
use App\Models\Quiz;
use App\Models\User;
use App\Services\EvaluationResultsRelease;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The rules that decide who may see what: campus scoping, the panel shield and
 * the moment an instructor may see their own results.
 */
it('shares the campus context of a signed-in user with the views', function () {
    ['offering' => $offering, 'student' => $student] = evaluationFixture();

    $this->actingAs($student);
    $request = Request::create('/');
    app(EnsureCampusAccess::class)->handle($request, fn ($request) => response('ok'));

    expect($request->attributes->get('is_super_admin'))->toBeFalse()
        ->and($request->attributes->has('campus_id'))->toBeTrue();
});

it('lets a guest through the campus middleware', function () {
    $request = Request::create('/');
    $response = app(EnsureCampusAccess::class)->handle($request, fn ($request) => response('ok'));

    expect($response->getStatusCode())->toBe(200)
        ->and($request->attributes->has('campus_id'))->toBeFalse();
});

it('marks a super admin for the views', function () {
    ['offering' => $offering] = evaluationFixture();
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));

    $this->actingAs($admin);
    $request = Request::create('/');
    app(EnsureCampusAccess::class)->handle($request, fn ($request) => response('ok'));

    expect($request->attributes->get('is_super_admin'))->toBeTrue();
});

it('closes an evaluation once the form has ended', function () {
    ['offering' => $offering] = evaluationFixture();

    expect(EvaluationResultsRelease::isClosed($offering))->toBeFalse();

    $offering->evaluationForm->update(['ends_at' => now()->subDay()]);
    expect(EvaluationResultsRelease::isClosed($offering))->toBeTrue();
});

it('closes an evaluation when the course offering is switched off', function () {
    ['offering' => $offering] = evaluationFixture();
    $offering->update(['is_active' => false]);

    expect(EvaluationResultsRelease::isClosed($offering))->toBeTrue();
});

it('closes an evaluation when the form is switched off', function () {
    ['offering' => $offering] = evaluationFixture();
    $offering->evaluationForm->update(['is_active' => false]);

    expect(EvaluationResultsRelease::isClosed($offering))->toBeTrue();
});

it('closes an evaluation when the semester has ended', function () {
    ['offering' => $offering] = evaluationFixture();
    $offering->semester->update(['end_date' => now()->subDay()]);

    expect(EvaluationResultsRelease::isClosed($offering))->toBeTrue();
});

it('releases the results once enough students have answered', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    $offering->evaluationForm->update(['ends_at' => now()->subDay()]);

    foreach (range(1, 4) as $number) {
        FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $offering->id, 'submission_fingerprint' => 'release-'.$number, 'data' => ['teaching_quality' => 5]]);
    }
    expect(EvaluationResultsRelease::isReleased($offering, 999))->toBeFalse();
    FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $offering->id, 'submission_fingerprint' => 'release-5', 'data' => ['teaching_quality' => 5]]);
    expect(EvaluationResultsRelease::isReleased($offering))->toBeTrue();
});

it('always needs at least one response', function () {
    config(['evaluations.min_responses_for_results' => 0]);

    expect(EvaluationResultsRelease::minimumResponses())->toBe(1);
});

it('has no reason to hold back results that are already out', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    $offering->evaluationForm->update(['ends_at' => now()->subDay()]);
    for ($i = 0; $i < 5; $i++) {
        $form->submissions()->create([
            'course_offering_id' => $offering->id,
            'submission_fingerprint' => 'fp-'.$i,
            'data' => ['teaching_quality' => 5],
        ]);
    }

    expect(EvaluationResultsRelease::pendingReason($offering))->toBeNull();
});

it('lists the assignments a teacher has answers for', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    $evaluatee = $offering->evaluatee;

    expect(EvaluationResultsRelease::ownOfferings($evaluatee))->toHaveCount(0);

    $form->submissions()->create([
        'course_offering_id' => $offering->id,
        'submission_fingerprint' => 'fp-1',
        'data' => ['teaching_quality' => 5],
    ]);

    expect(EvaluationResultsRelease::ownOfferings($evaluatee))->toHaveCount(1);
});

it('keeps an assessment out of reach for an instructor who does not own it', function () {
    ['offering' => $offering] = evaluationFixture();
    $quiz = Quiz::create([
        'course_offering_id' => $offering->id,
        'title' => 'PHP Basics',
        'type' => 'quiz',
        'duration_minutes' => 20,
        'max_attempts' => 1,
        'is_published' => false,
        'assign_to_all' => true,
    ]);

    $other = User::factory()->create(['is_instructor' => true]);
    $other->givePermissionTo(Permission::findOrCreate('update_quiz', 'web'));
    $other->givePermissionTo(Permission::findOrCreate('view_quiz', 'web'));

    expect($other->can('update', $quiz))->toBeFalse();
});

it('lets the owner of the class update its assessment', function () {
    ['offering' => $offering] = evaluationFixture();
    $quiz = Quiz::create([
        'course_offering_id' => $offering->id,
        'title' => 'PHP Basics',
        'type' => 'quiz',
        'duration_minutes' => 20,
        'max_attempts' => 1,
        'is_published' => false,
        'assign_to_all' => true,
    ]);

    $instructor = $offering->evaluatee;
    $instructor->givePermissionTo(Permission::findOrCreate('update_quiz', 'web'));

    expect($instructor->can('update', $quiz))->toBeTrue();
});

it('lets a super admin update anybody\'s assessment', function () {
    ['offering' => $offering] = evaluationFixture();
    $quiz = Quiz::create([
        'course_offering_id' => $offering->id,
        'title' => 'PHP Basics',
        'type' => 'quiz',
        'duration_minutes' => 20,
        'max_attempts' => 1,
        'is_published' => false,
        'assign_to_all' => true,
    ]);

    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    $admin->givePermissionTo(Permission::findOrCreate('update_quiz', 'web'));

    expect($admin->can('update', $quiz))->toBeTrue();
});
