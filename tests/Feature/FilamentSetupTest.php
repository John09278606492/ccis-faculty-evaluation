<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\CourseResource\Pages\ListCourses;
use App\Filament\Resources\DepartmentResource\Pages\CreateDepartment;
use App\Filament\Resources\DepartmentResource\Pages\ListDepartments;
use App\Filament\Resources\RoleResource;
use App\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Filament\Resources\SchoolYearResource\Pages\CreateSchoolYear;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Models\Campus;
use App\Models\Course;
use App\Models\Department;
use App\Models\SchoolYear;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The panel's setup pages: departments, courses, school years, users and roles.
 */
beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/**
 * A super admin with every permission the panel checks for.
 */
function panelAdmin(array $permissions = []): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    foreach ($permissions as $permission) {
        $admin->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $admin;
}

it('shows the departments of the current campus only', function () {
    ['offering' => $offering] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $other = Department::create([
        'campus_id' => Campus::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'is_active' => true])->id,
        'name' => 'Nursing',
        'code' => 'NUR',
        'is_active' => true,
    ]);
    $this->actingAs(panelAdmin(['view_any_department', 'view_department']));

    Livewire::test(ListDepartments::class)
        ->assertCanSeeTableRecords([$offering->course->department])
        ->assertCanNotSeeTableRecords([$other]);
});

it('creates a department in the current campus', function () {
    ['offering' => $offering] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(panelAdmin(['view_any_department', 'create_department']));

    Livewire::test(CreateDepartment::class)
        ->fillForm(['name' => 'Nursing', 'code' => 'nur', 'description' => 'Care studies'])
        ->call('create')
        ->assertHasNoFormErrors();

    $department = Department::where('name', 'Nursing')->sole();
    expect($department->campus_id)->toBe($offering->campus->id)
        // Codes are stored in upper case.
        ->and($department->code)->toBe('NUR');
});

it('shows the courses of the current campus only', function () {
    ['offering' => $offering] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $other = Course::create([
        'department_id' => Department::create([
            'campus_id' => Campus::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'is_active' => true])->id,
            'name' => 'Nursing',
            'code' => 'NUR',
            'is_active' => true,
        ])->id,
        'code' => 'NUR101',
        'name' => 'Fundamentals of Nursing',
        'is_active' => true,
    ]);
    $this->actingAs(panelAdmin(['view_any_course', 'view_course']));

    Livewire::test(ListCourses::class)
        ->assertCanSeeTableRecords([$offering->course])
        ->assertCanNotSeeTableRecords([$other]);
});

it('creates a school year for the current campus', function () {
    ['offering' => $offering] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(panelAdmin(['view_any_school::year', 'create_school::year']));

    Livewire::test(CreateSchoolYear::class)
        ->fillForm([
            'name' => '2027-2028',
            'start_date' => '2027-06-01',
            'end_date' => '2028-05-31',
            'is_current' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(SchoolYear::where('name', '2027-2028')->sole()->campus_id)->toBe($offering->campus->id);
});

it('hides the super admin role from other administrators', function () {
    ['offering' => $offering] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    Role::findOrCreate('super_admin', 'web');
    Role::findOrCreate('student', 'web');
    // An ordinary administrator, not a super admin.
    $admin = User::factory()->create();
    $admin->givePermissionTo(Permission::findOrCreate('view_any_role', 'web'));
    $admin->givePermissionTo(Permission::findOrCreate('view_role', 'web'));
    $this->actingAs($admin);

    Livewire::test(ListRoles::class)
        ->assertCanNotSeeTableRecords([Role::findByName('super_admin')]);
});

it('keeps the super admin role itself out of reach', function () {
    ['offering' => $offering] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $role = Role::findOrCreate('super_admin', 'web');

    expect(RoleResource::canDelete($role))->toBeFalse();
});

it('lets a super admin delete an ordinary role', function () {
    ['offering' => $offering] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $role = Role::findOrCreate('student', 'web');
    $admin = panelAdmin(['delete_role']);
    $this->actingAs($admin);

    expect(RoleResource::canDelete($role))->toBeTrue();
});

it('keeps a global role out of an ordinary administrator\'s reach', function () {
    ['offering' => $offering] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $global = Role::findOrCreate('student', 'web');
    $admin = User::factory()->create();
    $admin->givePermissionTo(Permission::findOrCreate('delete_role', 'web'));
    $this->actingAs($admin);

    expect(RoleResource::canDelete($global))->toBeFalse()
        ->and(RoleResource::canEdit($global))->toBeFalse();
});

it('keeps academic students separate from staff accounts', function () {
    ['offering' => $offering, 'student' => $student] = evaluationFixture();
    $offering->evaluatee->campuses()->attach($offering->campus);
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(panelAdmin(['view_any_user', 'view_user']));

    Livewire::test(ListUsers::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$offering->evaluatee])
        ->assertCanNotSeeTableRecords([$student]);
});

it('counts the campus in the dashboard statistics', function () {
    ['offering' => $offering, 'student' => $student] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(panelAdmin(['view_any_user', 'view_any_department', 'view_any_course', 'view_any_course::offering', 'view_any_form']));

    Livewire::test(StatsOverviewWidget::class)
        ->assertSuccessful();
});

it('shows the dashboard to a super admin', function () {
    ['offering' => $offering] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(panelAdmin());

    Livewire::test(Dashboard::class)->assertSuccessful();
});
