<?php

use App\Filament\Resources\DepartmentResource\Pages\EditDepartment;
use App\Filament\Resources\DepartmentResource\Pages\ListDepartments;
use App\Filament\Resources\DepartmentResource\RelationManagers\InstructorsRelationManager;
use App\Models\Campus;
use App\Models\Department;
use App\Models\FormSubmission;
use App\Models\User;
use App\Services\AssignedDataAccess;
use App\Services\DepartmentInstructors;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    ['offering' => $this->class, 'student' => $this->student] = evaluationFixture();
    $this->campus = $this->class->campus;
    $this->department = $this->class->course->department;
    $this->actor = User::factory()->create(['is_student' => false]);
    $this->actor->assignRole('super_admin');
    $this->actor->campuses()->attach($this->campus);
    foreach (['view_any_department', 'view_department', 'update_department', 'update_user', 'view_any_user', DepartmentInstructors::TRANSFER_PERMISSION] as $permission) {
        $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->teacher = $this->class->instructor;
    $this->teacher->update(['instructor_department_id' => null]);
    $this->teacher->campuses()->attach($this->campus);
    $this->second = User::factory()->create(['is_student' => false, 'is_instructor' => true, 'instructor_department_id' => null]);
    $this->second->campuses()->attach($this->campus);
    $this->service = app(DepartmentInstructors::class);
    $this->actingAs($this->actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
});

it('assigns several home departments once while preserving classes students supervisors and responses', function () {
    $this->class->students()->syncWithoutDetaching([$this->student->id]);
    $response = FormSubmission::create(['form_id' => $this->class->form_id, 'course_offering_id' => $this->class->id, 'data' => ['teaching_quality' => 4, '_overall_comment' => 'Keep this']]);
    $before = $response->fresh()->getRawOriginal();
    $classBefore = $this->class->fresh()->getRawOriginal();
    $count = $this->service->assign($this->actor, $this->campus, $this->department, [$this->teacher->id, $this->second->id, $this->teacher->id]);
    expect($count)->toBe(2)->and($this->teacher->fresh()->instructor_department_id)->toBe($this->department->id)
        ->and($this->second->fresh()->instructor_department_id)->toBe($this->department->id)
        ->and($this->class->fresh()->getRawOriginal())->toBe($classBefore)
        ->and($response->fresh()->getRawOriginal())->toBe($before)
        ->and($this->class->students()->whereKey($this->student->id)->exists())->toBeTrue();
    expect($this->service->assign($this->actor, $this->campus, $this->department, [$this->teacher->id, $this->second->id]))->toBe(0)
        ->and(DB::table('access_audit_events')->where('action', 'home_department.assigned')->count())->toBe(2);
});

it('rejects invalid or cross-campus selections atomically instead of partially assigning instructors', function () {
    $outside = User::factory()->create(['is_student' => false, 'is_instructor' => true]);
    $staff = User::factory()->create(['is_student' => false, 'is_instructor' => false]);
    $staff->campuses()->attach($this->campus);
    $deleted = $this->second->replicate();
    $deleted->email = 'deleted@example.test';
    $deleted->save();
    $deleted->campuses()->attach($this->campus);
    $deleted->delete();
    foreach ([$outside->id, $staff->id, $deleted->id, $this->student->id, 999999] as $bad) {
        expect(fn () => $this->service->assign($this->actor, $this->campus, $this->department, [$this->teacher->id, $bad]))->toThrow(ValidationException::class);
        expect($this->teacher->fresh()->instructor_department_id)->toBeNull();
    }
    expect(fn () => $this->service->assign($this->actor, $this->campus, $this->department, []))->toThrow(ValidationException::class);
});

it('preserves instructors already assigned elsewhere including a selection changed after the modal opened', function () {
    $other = Department::create(['campus_id' => $this->campus->id, 'name' => 'CTAS', 'code' => 'CTAS', 'is_active' => true]);
    expect($this->service->options($this->actor, $this->campus, $this->department))->toHaveKey($this->second->id);
    $this->second->update(['instructor_department_id' => $other->id]);
    expect($this->service->options($this->actor, $this->campus, $this->department))->not->toHaveKey($this->second->id);
    expect(fn () => $this->service->assign($this->actor, $this->campus, $this->department, [$this->teacher->id, $this->second->id]))->toThrow(ValidationException::class);
    expect($this->teacher->fresh()->instructor_department_id)->toBeNull()->and($this->second->fresh()->instructor_department_id)->toBe($other->id);
});

it('enforces campus department and account-management permissions for a department head', function () {
    $this->actor->removeRole('super_admin');
    $this->actor->assignRole(Role::findOrCreate('chairperson', 'web'));
    $this->department->update(['chairperson_id' => $this->actor->id]);
    app(AssignedDataAccess::class)->actor = $this->actor;
    // Only the visiting faculty teaching in this department is visible; an unrelated unassigned account is not.
    expect($this->service->options($this->actor, $this->campus, $this->department))->toHaveKey($this->teacher->id)->not->toHaveKey($this->second->id);
    $other = Department::create(['campus_id' => $this->campus->id, 'name' => 'CTAS', 'code' => 'CTAS', 'is_active' => true]);
    expect(fn () => $this->service->assign($this->actor, $this->campus, $other, [$this->teacher->id]))->toThrow(HttpException::class);
    $otherCampus = Campus::create(['name' => 'Other Campus', 'slug' => 'other-campus', 'is_active' => true]);
    expect($this->service->allowed($this->actor, $otherCampus, $this->department))->toBeFalse();
    $this->actor->revokePermissionTo('update_user');
    expect(fn () => $this->service->assign($this->actor, $this->campus, $this->department, [$this->teacher->id]))->toThrow(HttpException::class);
});

it('hides inactive departments and refuses accounts with greater authority', function () {
    $this->actor->removeRole('super_admin');
    $this->actor->assignRole(Role::findOrCreate('chairperson', 'web'));
    $this->department->update(['chairperson_id' => $this->actor->id]);
    $this->teacher->givePermissionTo(Permission::findOrCreate('manage_access_assignments', 'web'));
    expect($this->service->options($this->actor, $this->campus, $this->department))->not->toHaveKey($this->teacher->id);
    expect(fn () => $this->service->assign($this->actor, $this->campus, $this->department, [$this->teacher->id]))->toThrow(ValidationException::class);
    $this->department->update(['is_active' => false]);
    expect($this->service->allowed($this->actor, $this->campus, $this->department))->toBeFalse();
});

it('provides the bulk picker directly on the department list and updates the instructor count', function () {
    Livewire::test(ListDepartments::class)->assertStatus(200)
        ->assertTableActionVisible('assignInstructors', $this->department)
        ->callTableAction('assignInstructors', $this->department, data: ['ids' => [$this->teacher->id, $this->second->id]])
        ->assertHasNoTableActionErrors();
    expect($this->department->fresh()->instructors()->count())->toBe(2);
});

it('offers the same bulk action on edit and shows the assigned instructor roster', function () {
    Livewire::test(EditDepartment::class, ['record' => $this->department->id])->assertStatus(200)
        ->assertSeeLivewire(InstructorsRelationManager::class)
        ->callAction('assignInstructors', data: ['ids' => [$this->teacher->id]])->assertHasNoActionErrors();
    Livewire::test(InstructorsRelationManager::class, ['ownerRecord' => $this->department, 'pageClass' => EditDepartment::class])->assertStatus(200)
        ->assertCanSeeTableRecords([$this->teacher])->assertCanNotSeeTableRecords([$this->second])
        ->callTableAction('assignInstructors', data: ['ids' => [$this->second->id]])->assertHasNoTableActionErrors()
        ->assertCanSeeTableRecords([$this->teacher, $this->second]);
});

it('transfers instructors to another college or removes them while keeping classes and responses', function () {
    $other = Department::create(['campus_id' => $this->campus->id, 'name' => 'CTAS', 'code' => 'CTAS', 'is_active' => true]);
    User::whereKey([$this->teacher->id, $this->second->id])->update(['instructor_department_id' => $this->department->id]);
    $this->class->students()->syncWithoutDetaching([$this->student->id]);
    $response = FormSubmission::create(['form_id' => $this->class->form_id, 'course_offering_id' => $this->class->id, 'data' => ['teaching_quality' => 4, '_overall_comment' => 'Keep this']]);
    $before = $response->fresh()->getRawOriginal();
    $classBefore = $this->class->fresh()->getRawOriginal();
    expect($this->service->destinations($this->actor, $this->campus, $this->department))->toHaveKey($other->id)->not->toHaveKey($this->department->id);

    expect($this->service->transfer($this->actor, $this->campus, $this->department, [$this->teacher->id, $this->teacher->id], $other->id))->toBe(1)
        ->and($this->teacher->fresh()->instructor_department_id)->toBe($other->id)
        ->and($this->second->fresh()->instructor_department_id)->toBe($this->department->id)
        ->and($this->class->fresh()->getRawOriginal())->toBe($classBefore)
        ->and($response->fresh()->getRawOriginal())->toBe($before)
        ->and($this->class->students()->whereKey($this->student->id)->exists())->toBeTrue();

    expect($this->service->transfer($this->actor, $this->campus, $this->department, [$this->second->id], null))->toBe(1)
        ->and($this->second->fresh()->instructor_department_id)->toBeNull()
        ->and($this->service->options($this->actor, $this->campus, $other))->toHaveKey($this->second->id)
        ->and(DB::table('access_audit_events')->where('action', 'home_department.transferred')->where('subject_id', $this->teacher->id)->count())->toBe(1)
        ->and(DB::table('access_audit_events')->where('action', 'home_department.removed')->where('subject_id', $this->second->id)->count())->toBe(1);
});

it('rejects invalid transfer destinations and selections without moving anyone', function () {
    $other = Department::create(['campus_id' => $this->campus->id, 'name' => 'CTAS', 'code' => 'CTAS', 'is_active' => true]);
    $inactive = Department::create(['campus_id' => $this->campus->id, 'name' => 'Closed', 'code' => 'CLOSED', 'is_active' => false]);
    $otherCampus = Campus::create(['name' => 'Other Campus', 'slug' => 'other-campus', 'is_active' => true]);
    $foreign = Department::create(['campus_id' => $otherCampus->id, 'name' => 'Foreign', 'code' => 'FOREIGN', 'is_active' => true]);
    $this->teacher->update(['instructor_department_id' => $this->department->id]);
    $this->second->update(['instructor_department_id' => $other->id]);

    foreach ([$this->department->id, $inactive->id, $foreign->id, 999999] as $destination) {
        expect(fn () => $this->service->transfer($this->actor, $this->campus, $this->department, [$this->teacher->id], $destination))->toThrow(ValidationException::class);
    }
    // One instructor of another college or one student in the selection stops the whole transfer.
    foreach ([$this->second->id, $this->student->id, 999999] as $bad) {
        expect(fn () => $this->service->transfer($this->actor, $this->campus, $this->department, [$this->teacher->id, $bad], $other->id))->toThrow(ValidationException::class);
    }
    expect(fn () => $this->service->transfer($this->actor, $this->campus, $this->department, [], $other->id))->toThrow(ValidationException::class);
    $this->teacher->assignRole(Role::findOrCreate('instructor', 'web'));
    $this->department->update(['chairperson_id' => $this->teacher->id]);
    expect(fn () => $this->service->transfer($this->actor, $this->campus, $this->department, [$this->teacher->id], $other->id))->toThrow(ValidationException::class, 'dean or chairperson');
    expect($this->teacher->fresh()->instructor_department_id)->toBe($this->department->id)
        ->and($this->second->fresh()->instructor_department_id)->toBe($other->id)
        ->and(DB::table('access_audit_events')->where('action', 'like', 'home_department.%')->count())->toBe(0);
});

it('requires the transfer permission on top of managing the department', function () {
    $other = Department::create(['campus_id' => $this->campus->id, 'name' => 'CTAS', 'code' => 'CTAS', 'is_active' => true]);
    User::whereKey([$this->teacher->id, $this->second->id])->update(['instructor_department_id' => $this->department->id]);
    $this->actor->removeRole('super_admin');
    $this->actor->assignRole(Role::findOrCreate('chairperson', 'web'));
    $this->actor->update(['is_instructor' => true, 'instructor_department_id' => $this->department->id]);
    $this->actor->revokePermissionTo(DepartmentInstructors::TRANSFER_PERMISSION);
    $this->department->update(['chairperson_id' => $this->actor->id]);
    app(AssignedDataAccess::class)->actor = $this->actor;

    expect($this->service->allowed($this->actor, $this->campus, $this->department))->toBeTrue()
        ->and($this->service->canTransfer($this->actor, $this->campus, $this->department))->toBeFalse()
        ->and($this->service->destinations($this->actor, $this->campus, $this->department))->toBe([]);
    expect(fn () => $this->service->transfer($this->actor, $this->campus, $this->department, [$this->teacher->id], $other->id))->toThrow(HttpException::class);

    $this->actor->givePermissionTo(Permission::findOrCreate(DepartmentInstructors::TRANSFER_PERMISSION, 'web'));
    $this->second->givePermissionTo(Permission::findOrCreate('manage_access_assignments', 'web'));
    // Not an account with more authority, not themselves, and not out of a college they do not manage.
    expect(fn () => $this->service->transfer($this->actor, $this->campus, $this->department, [$this->second->id], $other->id))->toThrow(ValidationException::class);
    expect(fn () => $this->service->transfer($this->actor, $this->campus, $this->department, [$this->actor->id], $other->id))->toThrow(ValidationException::class, 'own home department');
    expect(fn () => $this->service->transfer($this->actor, $this->campus, $other, [$this->teacher->id], null))->toThrow(HttpException::class);
    expect($this->service->transfer($this->actor, $this->campus, $this->department, [$this->teacher->id], $other->id))->toBe(1)
        ->and($this->teacher->fresh()->instructor_department_id)->toBe($other->id)
        ->and($this->second->fresh()->instructor_department_id)->toBe($this->department->id);
    $this->actor->revokePermissionTo('update_user');
    expect($this->service->canTransfer($this->actor->fresh(), $this->campus, $this->department))->toBeFalse();
});

it('offers transfer on each roster row and for a selection of instructors', function () {
    $other = Department::create(['campus_id' => $this->campus->id, 'name' => 'CTAS', 'code' => 'CTAS', 'is_active' => true]);
    $third = User::factory()->create(['is_student' => false, 'is_instructor' => true]);
    $third->campuses()->attach($this->campus);
    User::whereKey([$this->teacher->id, $this->second->id, $third->id])->update(['instructor_department_id' => $this->department->id]);

    Livewire::test(InstructorsRelationManager::class, ['ownerRecord' => $this->department, 'pageClass' => EditDepartment::class])->assertStatus(200)
        ->assertTableActionVisible('transferInstructor', $this->teacher)
        ->callTableAction('transferInstructor', $this->teacher, data: ['destination' => 'none'])->assertHasNoTableActionErrors()
        ->assertCanNotSeeTableRecords([$this->teacher])
        ->callTableBulkAction('transferInstructors', [$this->second, $third], data: ['destination' => $other->id])->assertHasNoTableBulkActionErrors()
        ->assertCanNotSeeTableRecords([$this->second, $third]);
    expect($this->teacher->fresh()->instructor_department_id)->toBeNull()
        ->and($this->second->fresh()->instructor_department_id)->toBe($other->id)
        ->and($third->fresh()->instructor_department_id)->toBe($other->id);
});
