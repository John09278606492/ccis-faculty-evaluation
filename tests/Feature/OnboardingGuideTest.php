<?php

use App\Filament\Pages\GettingStarted;
use App\Models\BackgroundTask;
use App\Models\FormSubmission;
use App\Models\User;
use App\Services\OnboardingGuide;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    ['student' => $this->student, 'offering' => $this->class] = evaluationFixture();
    $this->campus = $this->class->campus;
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
});

it('gives the owner an ordered full workflow without performing system actions', function () {
    $owner = User::factory()->create(['is_student' => false]);
    $owner->assignRole('super_admin'); $owner->campuses()->attach($this->campus);
    foreach (['view_any_campus', 'view_any_school::year', 'view_any_department', 'view_any_user', 'view_any_role',
        'view_academic_students', 'view_any_course::offering', 'view_any_form', 'view_any_evaluation::cycle',
        'manage_evaluators_course::offering', 'update_course::offering',
        'page_StudentEvaluationProgress', 'page_EvaluationResults', 'page_IndividualEvaluationCopies'] as $permission) {
        $owner->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($owner);
    $before = [User::count(), BackgroundTask::count(), FormSubmission::count()];
    $guide = app(OnboardingGuide::class)->forCurrentUser();
    $ids = array_column($guide['steps'], 'id');
    expect($ids)->toContain('campuses', 'period', 'departments', 'staff', 'roles', 'students', 'classes', 'forms', 'open', 'progress', 'reports', 'copies', 'close', 'recovery');
    expect(array_search('students', $ids))->toBeLessThan(array_search('open', $ids));
    Livewire::test(GettingStarted::class)->assertSee('Owner / super administrator')->assertSee('I understand this step')->assertSee('Know where to check system health')->assertSee('does not import');
    expect([User::count(), BackgroundTask::count(), FormSubmission::count()])->toBe($before);
});

it('shows students only their student workflow and scopes saved checklist keys per account and campus', function () {
    $this->actingAs($this->student);
    $this->student->givePermissionTo(Permission::findOrCreate('page_MyEvaluations', 'web'));
    $guide = app(OnboardingGuide::class)->forCurrentUser();
    expect(array_column($guide['steps'], 'id'))->toContain('workspace', 'evaluate')->not->toContain('staff', 'roles', 'open', 'reports');
    expect($guide['storage_key'])->toContain('-'.$this->student->id.'-'.$this->campus->id);
    Livewire::test(GettingStarted::class)->assertSee('Complete your student evaluations')->assertDontSee('Review roles and permissions');
});

it('includes permitted instructor and leader tasks without presenting owner-only setup', function () {
    $actor = $this->class->evaluatee;
    $actor->campuses()->syncWithoutDetaching([$this->campus->id]);
    $actor->assignRole(Role::findOrCreate('instructor', 'web'));
    foreach (['page_MyEvaluationResults', 'page_SupervisorEvaluations', 'page_StudentEvaluationProgress', 'page_EvaluationResults'] as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($actor);
    $ids = array_column(app(OnboardingGuide::class)->forCurrentUser()['steps'], 'id');
    expect($ids)->toContain('own-results', 'supervise', 'progress', 'reports')->not->toContain('roles', 'campuses', 'recovery');
    Livewire::test(GettingStarted::class)->assertSee('Complete assigned Annex B tasks')->assertSee('View your released results');
});

it('denies onboarding outside the assigned campus', function () {
    $this->actingAs(User::factory()->create(['is_student' => false]));
    expect(GettingStarted::canAccess())->toBeFalse();
    Livewire::test(GettingStarted::class)->assertForbidden();
});
