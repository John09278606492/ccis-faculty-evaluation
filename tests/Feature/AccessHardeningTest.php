<?php

use App\Filament\Pages\AccessAssignments;
use App\Filament\Resources\FormResource\Pages\EditForm;
use App\Filament\Resources\FormResource\RelationManagers\SubmissionsRelationManager;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Http\Middleware\ScopeAssignedData;
use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\User;
use App\Policies\FormSubmissionPolicy;
use App\Services\AssignedDataAccess;
use App\Services\ScopedAccountImport;
use App\Services\UserAccessAdministration;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    ['offering' => $this->offering, 'student' => $this->student] = evaluationFixture();
    $this->campus = $this->offering->campus;
    $this->actor = $this->offering->instructor;
    $this->actor->campuses()->attach($this->campus);
    $this->actor->assignRole(Role::findOrCreate('department_staff', 'web'));
    foreach (['view_any_user', 'update_user', 'create_user', 'view_form', 'update_form', 'import_academic_students', 'manage_academic_students'] as $permission) {
        $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($this->actor);
    Filament::setTenant($this->campus);
});

it('does not turn profile removal or user-management permission into campus-wide authority', function () {
    $scope = app(AssignedDataAccess::class);
    expect($scope->departmentIds($this->actor, $this->campus))->toBe([$this->offering->course->department_id]);
    $this->actor->update(['instructor_department_id' => null, 'is_instructor' => false]);
    expect($scope->departmentIds($this->actor, $this->campus))->toBe([]);
    DB::table('user_campus_access')->insert(['user_id' => $this->actor->id, 'campus_id' => $this->campus->id, 'scope' => 'campus', 'created_at' => now(), 'updated_at' => now()]);
    expect($scope->departmentIds($this->actor, $this->campus))->toBeNull();
});

it('requires dedicated role delegation and denies self-grants or permissions beyond the actor', function () {
    $subject = User::factory()->create(['is_student' => false, 'instructor_department_id' => $this->actor->instructor_department_id]);
    $subject->campuses()->attach($this->campus);
    $role = Role::create(['name' => 'campus_reviewer', 'guard_name' => 'web', 'team_id' => $this->campus->id]);
    $role->givePermissionTo(Permission::findOrCreate('view_form', 'web'));
    $service = app(UserAccessAdministration::class);
    expect(fn () => $service->assignRoles($this->actor, $subject, $this->campus, [$role->id]))->toThrow(HttpException::class);
    $this->actor->givePermissionTo(Permission::findOrCreate('assign_user_roles', 'web'));
    expect(fn () => $service->assignRoles($this->actor, $this->actor, $this->campus, [$role->id]))->toThrow(HttpException::class);
    $service->assignRoles($this->actor, $subject, $this->campus, [$role->id]);
    expect($subject->hasRole('campus_reviewer'))->toBeTrue();
    $role->givePermissionTo(Permission::findOrCreate('manage_access_assignments', 'web'));
    expect(fn () => $service->assignRoles($this->actor, $subject, $this->campus, [$role->id]))->toThrow(HttpException::class);
});

it('rechecks import scope without a Filament tenant and never adopts a foreign account', function () {
    $foreignCampus = Campus::create(['name' => 'Foreign', 'slug' => 'foreign', 'is_active' => true]);
    $foreign = User::factory()->create(['is_student' => true, 'student_id' => 'FOREIGN-123']);
    $foreign->campuses()->attach($foreignCampus);
    $options = ['campus_id' => $this->campus->id, 'department_id' => $this->actor->instructor_department_id];
    Filament::setTenant(null, isQuiet: true);
    $service = app(ScopedAccountImport::class);
    expect(fn () => $service->resolve($this->actor, $options, 'student', ['student_id' => $foreign->student_id, 'email' => $foreign->email]))->toThrow(ValidationException::class);
    $new = $service->resolve($this->actor, $options, 'instructor', ['employee_id' => 'NEW-123', 'email' => 'new@example.test']);
    $service->prepare($this->actor, $new, $options, 'instructor');
    expect(Hash::check('password123', $new->password))->toBeFalse()->and($new->must_change_password)->toBeTrue();
    $this->actor->revokePermissionTo('create_user');
    expect(fn () => $service->resolve($this->actor, $options, 'instructor', ['employee_id' => 'NEW-123', 'email' => 'new@example.test']))->toThrow(HttpException::class);
});

it('denies another departments direct form preview', function () {
    $other = Department::create(['name' => 'Other', 'code' => 'OTHER', 'campus_id' => $this->campus->id, 'is_active' => true]);
    $form = Form::create(['name' => 'Restricted Form', 'slug' => 'restricted-form', 'is_active' => true]);
    $form->campuses()->attach($this->campus);
    $form->departments()->attach($other);
    $this->get(route('form.preview', $form))->assertForbidden();
    $this->get(route('form.preview', $this->offering->evaluationForm))->assertOk();
});

it('keeps legacy submission identities hidden and prevents destructive response deletion', function () {
    $submission = FormSubmission::create(['form_id' => $this->offering->form_id, 'course_offering_id' => $this->offering->id, 'user_id' => $this->student->id,
        'ip_address' => '198.51.100.9', 'submitted_at' => now(), 'data' => ['teaching_quality' => 5]]);
    expect(Gate::getPolicyFor($submission))->toBeInstanceOf(FormSubmissionPolicy::class);
    expect($this->actor->can('delete', $submission))->toBeFalse();
    Livewire::test(SubmissionsRelationManager::class, ['ownerRecord' => $this->offering->evaluationForm, 'pageClass' => EditForm::class])
        ->assertTableActionDoesNotExist('delete')->assertTableBulkActionDoesNotExist('delete')->assertDontSee($this->student->name)->assertDontSee('198.51.100.9');
});

it('prevents resetting a higher-authority staff account to bypass role delegation', function () {
    $subject = User::factory()->create(['is_student' => false, 'instructor_department_id' => $this->actor->instructor_department_id]);
    $subject->campuses()->attach($this->campus);
    $subject->givePermissionTo(Permission::findOrCreate('manage_access_assignments', 'web'));
    expect(app(UserAccessAdministration::class)->canManageAccount($this->actor, $subject, $this->campus))->toBeFalse();
    expect(UserResource::canEdit($subject))->toBeFalse();
});

it('allows explicit department delegation but denies self-elevation and foreign departments', function () {
    $this->actor->givePermissionTo(Permission::findOrCreate('manage_access_assignments', 'web'));
    $subject = User::factory()->create(['is_student' => false]);
    $subject->campuses()->attach($this->campus);
    $department = $this->offering->course->department_id;
    $service = app(UserAccessAdministration::class);
    expect(fn () => $service->setScope($this->actor, $this->actor, $this->campus, 'campus', []))->toThrow(HttpException::class);
    expect(fn () => $service->setScope($this->actor, $subject, $this->campus, 'campus', []))->toThrow(HttpException::class);
    $service->setScope($this->actor, $subject, $this->campus, 'departments', [$department]);
    expect(app(AssignedDataAccess::class)->departmentIds($subject, $this->campus))->toBe([$department]);
    expect(DB::table('access_audit_events')->where('subject_id', $subject->id)->where('action', 'scope.changed')->exists())->toBeTrue();
});

it('preserves protected global profile roles while editing delegated campus roles', function () {
    $this->actor->givePermissionTo(Permission::findOrCreate('assign_user_roles', 'web'));
    $subject = User::factory()->create(['is_student' => false, 'instructor_department_id' => $this->actor->instructor_department_id]);
    $subject->campuses()->attach($this->campus);
    $global = Role::findOrCreate('instructor', 'web');
    $local = Role::create(['name' => 'local_form_reviewer', 'guard_name' => 'web', 'team_id' => $this->campus->id]);
    $local->givePermissionTo(Permission::findOrCreate('view_form', 'web'));
    $subject->assignRole([$global, $local]);
    Livewire::test(EditUser::class, ['record' => $subject->id])
        ->fillForm(['first_name' => 'Updated', 'last_name' => 'Staff'])->call('save')->assertHasNoFormErrors();
    expect($subject->fresh()->hasRole('instructor'))->toBeTrue();
    expect($subject->fresh()->hasRole('local_form_reviewer'))->toBeTrue();
});

it('shows effective access and records explicit department assignments in the admin page', function () {
    $this->actor->givePermissionTo(Permission::findOrCreate('manage_access_assignments', 'web'));
    Livewire::test(AccessAssignments::class)->assertSuccessful()->assertSee('Access &amp; Assignments', false)->assertSee($this->actor->name);
});

it('rejects hybrid student accounts carrying staff permissions and cleans profile conversions', function () {
    $this->student->givePermissionTo(Permission::findOrCreate('view_any_user', 'web'));
    $request = Request::create('/1');
    $request->setUserResolver(fn () => $this->student);
    expect(fn () => app(ScopeAssignedData::class)->handle($request, fn () => response('unsafe')))->toThrow(HttpException::class);
    app(UserAccessAdministration::class)->enforceProfileRoles($this->student);
    expect($this->student->fresh()->can('view_any_user'))->toBeFalse();
    expect($this->student->fresh()->hasRole('student'))->toBeTrue();
});

it('uses the explicit teaching department before the course department for visibility', function () {
    $other = Department::create(['name' => 'Host Department', 'code' => 'HOST', 'campus_id' => $this->campus->id, 'is_active' => true]);
    $this->offering->update(['teaching_department_id' => $other->id]);
    expect(app(AssignedDataAccess::class)->apply(CourseOffering::query(), $this->actor, $this->campus)->whereKey($this->offering->id)->exists())->toBeFalse();
    DB::table('user_department_access')->insert(['user_id' => $this->actor->id, 'department_id' => $other->id, 'created_at' => now(), 'updated_at' => now()]);
    expect(app(AssignedDataAccess::class)->apply(CourseOffering::query(), $this->actor, $this->campus)->whereKey($this->offering->id)->exists())->toBeTrue();
});

it('keeps department options scoped after a real Livewire update request', function () {
    $this->actor->givePermissionTo(Permission::findOrCreate('page_EvaluationResults', 'web'));
    Department::create(['name' => 'Restricted Foreign Department', 'code' => 'FOREIGN-SCOPE', 'campus_id' => $this->campus->id, 'is_active' => true]);
    $response = $this->get(route('filament.admin.pages.evaluation-results', ['tenant' => $this->campus->id]))->assertOk();
    preg_match_all('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
    $snapshot = collect($matches[1])->map(fn ($value) => html_entity_decode($value, ENT_QUOTES))
        ->first(fn ($value) => str_contains(json_decode($value, true)['memo']['name'], 'evaluation-results'));
    expect($snapshot)->not->toBeNull();
    $updated = $this->withHeader('X-Livewire', 'true')->postJson('/livewire/update', ['components' => [
        ['snapshot' => $snapshot, 'updates' => [], 'calls' => []],
    ]])->assertOk();
    expect($updated->json('components.0.effects.html'))->toContain($this->offering->course->department->name)->not->toContain('Restricted Foreign Department');
});
