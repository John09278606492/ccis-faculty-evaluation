<?php

use App\Models\Campus;
use App\Models\Department;
use App\Models\User;
use App\Services\EvaluationAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->access = app(EvaluationAccessService::class);
});

it('lets an enrolled student on a matching role use the form', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();

    expect($this->access->authorize($offering, $student)->id)->toBe($offering->form_id);
});

it('refuses a course offering that is not active', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $offering->update(['is_active' => false]);

    expect(fn () => $this->access->authorize($offering, $student))
        ->toThrow(AuthorizationException::class, 'This course offering is not available for evaluation.');
});

it('refuses a course offering with no form assigned', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $offering->update(['form_id' => null]);

    expect(fn () => $this->access->authorize($offering, $student))
        ->toThrow(AuthorizationException::class, 'This course offering is not available for evaluation.');
});

it('refuses a form that is not assigned to the course offering campus', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $other = Campus::create(['name' => 'Other Campus', 'slug' => 'other', 'is_active' => true]);
    $offering->evaluationForm->campuses()->sync([$other->id]);

    expect(fn () => $this->access->authorize($offering, $student))
        ->toThrow(AuthorizationException::class, 'This evaluation form is not assigned to the course offering campus.');
});

it('refuses a form that is not assigned to the course offering department', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $other = Department::create([
        'campus_id' => $offering->campus->id,
        'name' => 'Nursing',
        'code' => 'NUR',
        'is_active' => true,
    ]);
    $offering->evaluationForm->departments()->sync([$other->id]);

    expect(fn () => $this->access->authorize($offering, $student))
        ->toThrow(AuthorizationException::class, 'This evaluation form is not assigned to the course offering department.');
});

it('lets a guest in when the form does not require a login', function () {
    ['offering' => $offering] = evaluationFixture();
    $offering->evaluationForm->update(['requires_login' => false]);

    expect($this->access->authorize($offering, null)->id)->toBe($offering->form_id);
});

it('refuses a guest when the form requires a login', function () {
    ['offering' => $offering] = evaluationFixture();

    expect(fn () => $this->access->authorize($offering, null))
        ->toThrow(AuthorizationException::class, 'You must log in to access this evaluation form.');
});

it('lets a super admin through whatever their campus', function () {
    ['offering' => $offering] = evaluationFixture();
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));

    expect($this->access->authorize($offering, $admin)->id)->toBe($offering->form_id);
});

it('refuses a user from another campus', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $student->campuses()->detach();
    $student->campuses()->attach(Campus::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'is_active' => true])->id);

    expect(fn () => $this->access->authorize($offering, $student))
        ->toThrow(AuthorizationException::class, 'You do not have access to this campus.');
});

it('refuses a student who is not enrolled in the course offering', function () {
    ['offering' => $offering] = evaluationFixture();
    $stranger = User::factory()->create(['is_student' => true]);
    $stranger->assignRole(Role::findOrCreate('student', 'web'));
    $stranger->campuses()->attach($offering->campus->id);

    expect(fn () => $this->access->authorize($offering, $stranger))
        ->toThrow(AuthorizationException::class, 'You are not enrolled in this course offering.');
});

it('refuses a user whose role may not evaluate', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $offering->evaluationForm->update(['evaluator_roles' => ['instructor']]);

    expect(fn () => $this->access->authorize($offering, $student))
        ->toThrow(AuthorizationException::class, 'Your role is not allowed to submit this evaluation.');
});

it('allows any role when the form lists none', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $offering->evaluationForm->update(['evaluator_roles' => []]);

    expect($this->access->authorize($offering, $student)->id)->toBe($offering->form_id);
});

it('refuses a student who is not enrolled when the form lists no roles', function () {
    ['offering' => $offering] = evaluationFixture();
    $offering->evaluationForm->update(['evaluator_roles' => []]);
    $stranger = User::factory()->create(['is_student' => true]);
    $stranger->assignRole(Role::findOrCreate('student', 'web'));
    $stranger->campuses()->attach($offering->campus->id);

    expect(fn () => $this->access->authorize($offering, $stranger))
        ->toThrow(AuthorizationException::class, 'You are not enrolled in this course offering.');
});

it('lets a non-student evaluator in when they are enrolled', function () {
    ['offering' => $offering] = evaluationFixture();
    $offering->evaluationForm->update(['evaluator_roles' => ['instructor']]);
    $instructor = User::factory()->create(['is_instructor' => true]);
    $instructor->assignRole(Role::findOrCreate('instructor', 'web'));
    $instructor->campuses()->attach($offering->campus->id);
    $offering->students()->attach($instructor, ['enrolled_at' => now()]);

    expect($this->access->authorize($offering, $instructor)->id)->toBe($offering->form_id);
});
