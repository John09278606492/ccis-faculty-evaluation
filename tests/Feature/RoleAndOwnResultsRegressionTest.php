<?php

use App\Filament\Resources\RoleResource;
use App\Filament\Resources\RoleResource\Pages\EditRole;
use App\Models\Role;
use App\Services\EvaluationResultsRelease;
use App\Services\EvaluationTabularReports;
use App\Services\ReportService;
use App\Services\UserAccessAdministration;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('lets authorized faculty reviewers see their own current summary and reports', function () {
    ['offering' => $class, 'form' => $form] = evaluationFixture();
    $faculty = $class->evaluatee;
    $faculty->campuses()->syncWithoutDetaching([$class->campus->id]);
    $faculty->givePermissionTo(Permission::findOrCreate('page_EvaluationResults', 'web'));
    $this->actingAs($faculty);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($class->campus, isQuiet: true);
    $form->submissions()->create(['course_offering_id' => $class->id, 'submission_fingerprint' => 'review-own', 'data' => ['teaching_quality' => 4]]);

    expect(EvaluationResultsRelease::isReleased($class))->toBeFalse()
        ->and(EvaluationResultsRelease::canSeeScores($faculty, $class))->toBeTrue();
    $summary = app(ReportService::class)->getEvaluateeSummary($faculty, context: ['campus_id' => $class->campus->id]);
    expect($summary['total_courses'])->toBe(1)->and($summary['complete_responses'])->toBe(1);
    $this->get(route('evaluation.report', $class))->assertOk()->assertSee('80.00');
    expect(app(EvaluationTabularReports::class)->build($faculty, $class))->not->toBeEmpty();

    $faculty->revokePermissionTo('page_EvaluationResults');
    expect(EvaluationResultsRelease::canSeeScores($faculty, $class))->toBeFalse();
    expect(app(ReportService::class)->getEvaluateeSummary($faculty)['total_courses'])->toBe(0);
});

it('saves registered Shield permissions that have not yet been generated in the database', function () {
    ['offering' => $class] = evaluationFixture();
    $owner = $class->evaluatee;
    $owner->assignRole(Role::findOrCreate('super_admin', 'web'));
    foreach (['view_any_role', 'view_role', 'update_role'] as $name) {
        $owner->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }
    $this->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($class->campus, isQuiet: true);
    $role = Role::create(['name' => 'reviewer', 'guard_name' => 'web', 'team_id' => $class->campus->id]);
    $permission = array_key_first(RoleResource::getPageOptions());
    Permission::where('name', $permission)->delete();
    Livewire::test(EditRole::class, ['record' => $role->id])
        ->fillForm(['pages_tab' => [$permission]])->call('save')->assertHasNoFormErrors()->assertNotified();
    expect($role->fresh()->hasPermissionTo($permission))->toBeTrue();
    expect(fn () => app(UserAccessAdministration::class)->validateRolePermissions($owner, $class->campus, ['invented_admin_privilege']))
        ->toThrow(HttpException::class);
    $owner->removeRole('super_admin');
    expect(fn () => app(UserAccessAdministration::class)->validateRolePermissions($owner, $class->campus, [$permission]))
        ->toThrow(HttpException::class);
});
