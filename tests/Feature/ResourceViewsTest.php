<?php

use App\Filament\Resources\CampusResource\Pages\ListCampuses;
use App\Filament\Resources\CourseOfferingResource\Pages\ListCourseOfferings;
use App\Filament\Resources\CourseResource\Pages\ListCourses;
use App\Filament\Resources\DepartmentResource\Pages\ListDepartments;
use App\Filament\Resources\FormResource\Pages\ListForms;
use App\Filament\Resources\SchoolYearResource\Pages\ListSchoolYears;
use App\Filament\Resources\UserResource;
use App\Models\Department;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    ['offering' => $this->offering] = evaluationFixture();
    $this->campus = $this->offering->campus;
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    $this->admin = User::factory()->create(['is_student' => false]);
    $this->admin->assignRole('super_admin');
    foreach (['user', 'campus', 'course', 'department', 'school::year', 'form', 'course::offering'] as $resource) {
        foreach (['view_any_', 'view_'] as $prefix) {
            $this->admin->givePermissionTo(Permission::findOrCreate($prefix.$resource, 'web'));
        }
    }
    $this->actingAs($this->admin);
});

it('opens staff account details through the actual panel route without building editable form components', function (bool $instructor) {
    $subject = User::factory()->create(['is_student' => false, 'is_instructor' => $instructor, 'instructor_department_id' => $this->offering->course->department_id]);
    $subject->campuses()->attach($this->campus);
    $subject->assignRole(Role::findOrCreate('instructor', 'web'));
    $this->get(UserResource::getUrl('view', ['record' => $subject, 'tenant' => $this->campus]))
        ->assertOk()->assertSee($subject->name)->assertSee($subject->email)->assertSee('User Information')
        ->assertDontSee('Student Profile')->assertDontSee($subject->password);
})->with([true, false]);

it('continues to enforce staff view permission and department scope', function () {
    $actor = User::factory()->create(['is_student' => false, 'instructor_department_id' => $this->offering->course->department_id]);
    $actor->campuses()->attach($this->campus);
    $actor->assignRole(Role::findOrCreate('department_staff', 'web'));
    $actor->givePermissionTo('view_any_user', 'view_user');
    $subject = User::factory()->create(['is_student' => false, 'instructor_department_id' => $actor->instructor_department_id]);
    $subject->campuses()->attach($this->campus);
    $url = UserResource::getUrl('view', ['record' => $subject, 'tenant' => $this->campus]);
    $this->actingAs($actor)->get($url)->assertOk()->assertSee($subject->email);
    $actor->revokePermissionTo('view_user');
    $this->get($url)->assertForbidden();
    $actor->givePermissionTo('view_user');
    $department = Department::create(['campus_id' => $this->campus->id, 'name' => 'Other Department', 'code' => 'OTHER', 'is_active' => true]);
    $subject->update(['instructor_department_id' => $department->id]);
    $this->get($url)->assertNotFound()->assertDontSee($subject->email);
});

it('opens the existing academic record view modals', function (string $resource) {
    [$page, $record] = match ($resource) {
        'campus' => [ListCampuses::class, $this->campus],
        'department' => [ListDepartments::class, $this->offering->course->department],
        'course' => [ListCourses::class, $this->offering->course],
        'school_year' => [ListSchoolYears::class, $this->offering->semester->schoolYear],
        'form' => [ListForms::class, $this->offering->evaluationForm],
        'assignment' => [ListCourseOfferings::class, $this->offering],
    };
    Livewire::test($page)->mountTableAction('view', $record)->assertSuccessful()->assertTableActionMounted('view');
})->with(['campus', 'department', 'course', 'school_year', 'form', 'assignment']);
