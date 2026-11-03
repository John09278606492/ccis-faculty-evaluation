<?php

use App\Filament\Resources\CourseOfferingResource\Pages\EditCourseOffering;
use App\Filament\Resources\CourseOfferingResource\RelationManagers\StudentsRelationManager;
use App\Models\Campus;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    ['student' => $this->student, 'offering' => $this->offering] = evaluationFixture();
    $this->campus = $this->offering->campus;
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    $this->manager = $this->offering->instructor;
    $this->manager->campuses()->attach($this->campus);
    $this->role = Role::findOrCreate('evaluation_coordinator', 'web');
    foreach (['view_course::offering', 'update_course::offering', 'view_any_user'] as $permission) {
        $this->role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    Permission::findOrCreate('manage_evaluators_course::offering', 'web');
    $this->manager->assignRole($this->role);
    $this->actingAs($this->manager);
});

it('does not let assignment ownership or user management bypass the role permission', function () {
    expect($this->manager->can('manageEvaluators', $this->offering))->toBeFalse();
    Livewire::test(StudentsRelationManager::class, ['ownerRecord' => $this->offering, 'pageClass' => EditCourseOffering::class])
        ->assertTableActionHidden('attach')
        ->assertTableActionHidden('bulkAttach')
        ->assertTableActionDoesNotExist('bulkAttachCsv')
        ->assertTableActionHidden('detach', $this->student)
        ->assertTableBulkActionHidden('detach');
});

it('allows an explicitly authorized role to attach and detach evaluators', function () {
    $this->role->givePermissionTo('manage_evaluators_course::offering');
    $this->offering->students()->detach();
    $component = Livewire::test(StudentsRelationManager::class, ['ownerRecord' => $this->offering, 'pageClass' => EditCourseOffering::class]);
    $component->assertTableActionVisible('assignSections')
        ->callTableAction('attach', data: ['recordId' => $this->student->id])->assertHasNoTableActionErrors();
    expect($this->offering->students()->whereKey($this->student)->exists())->toBeTrue();
    $component->callTableAction('detach', $this->student);
    expect($this->offering->students()->count())->toBe(0);
    $component->callTableAction('bulkAttach', data: ['student_ids' => [$this->student->id]])->assertHasNoTableActionErrors();
    expect($this->offering->students()->count())->toBe(1);
    $component->callTableBulkAction('detach', [$this->student]);
    expect($this->offering->students()->count())->toBe(0);
});

it('rechecks revoked permissions after an assignment dialog has opened', function () {
    $this->role->givePermissionTo('manage_evaluators_course::offering');
    $this->offering->students()->detach();
    $component = Livewire::test(StudentsRelationManager::class, ['ownerRecord' => $this->offering, 'pageClass' => EditCourseOffering::class])
        ->mountTableAction('bulkAttach');
    $this->role->revokePermissionTo('manage_evaluators_course::offering');
    $this->manager->unsetRelation('roles')->unsetRelation('permissions');
    $component->setTableActionData(['student_ids' => [$this->student->id]])->callMountedTableAction();
    expect($this->offering->students()->count())->toBe(0);
});

it('uses saved section selection and keeps file uploads in the Sections module', function () {
    $this->role->givePermissionTo('manage_evaluators_course::offering');
    $this->offering->students()->detach();
    Livewire::test(StudentsRelationManager::class, ['ownerRecord' => $this->offering, 'pageClass' => EditCourseOffering::class])
        ->assertTableActionDoesNotExist('bulkAttachCsv')->assertTableActionVisible('assignSections');
    expect($this->offering->students()->count())->toBe(0);
});

it('requires update access and enforces the selected campus and student exclusion', function () {
    $this->role->givePermissionTo('manage_evaluators_course::offering');
    expect($this->manager->can('manageEvaluators', $this->offering))->toBeTrue();
    $this->role->revokePermissionTo('update_course::offering');
    $this->manager->unsetRelation('roles');
    expect($this->manager->can('manageEvaluators', $this->offering))->toBeFalse();
    $this->role->givePermissionTo('update_course::offering');
    $this->manager->unsetRelation('roles');
    Filament::setTenant(Campus::create(['name' => 'Other', 'slug' => 'other', 'is_active' => true]), isQuiet: true);
    expect($this->manager->can('manageEvaluators', $this->offering))->toBeFalse();
    Filament::setTenant($this->campus, isQuiet: true);
    $this->student->assignRole($this->role);
    expect($this->student->can('manageEvaluators', $this->offering))->toBeFalse();
});
