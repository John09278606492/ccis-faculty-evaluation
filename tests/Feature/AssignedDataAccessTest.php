<?php

use App\Models\Campus;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\FormSubmission;
use App\Models\User;
use App\Services\AssignedDataAccess;
use App\Services\EvaluationCopies;
use App\Services\EvaluationTemplates;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    ['offering' => $this->offering, 'student' => $this->student] = evaluationFixture();
    $this->campus = $this->offering->campus;
    $this->head = User::factory()->create(['is_student' => false, 'instructor_department_id' => $this->offering->course->department_id]);
    $this->head->campuses()->attach($this->campus);
    foreach (['page_EvaluationResults', 'page_StudentEvaluationProgress', 'view_any_user', 'update_user'] as $permission) {
        $this->head->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->otherDepartment = Department::create(['name' => 'Other Department', 'code' => 'OTHER', 'campus_id' => $this->campus->id, 'is_active' => true]);
    $course = Course::create(['name' => 'Other Course', 'code' => 'OTHER-101', 'department_id' => $this->otherDepartment->id, 'is_active' => true]);
    $this->otherOffering = $this->offering->replicate();
    $this->otherOffering->course_id = $course->id;
    $this->otherOffering->save();
    $this->otherStudent = User::factory()->create(['is_student' => true]);
    $this->otherStudent->campuses()->attach($this->campus);
    $this->otherOffering->students()->attach($this->otherStudent);
    $this->actingAs($this->head);
});

it('scopes panel lists counts and direct record lookups despite broad permissions', function () {
    Filament::setTenant($this->campus);
    app(AssignedDataAccess::class)->actor = $this->head;
    expect(Department::pluck('id')->all())->toBe([$this->offering->course->department_id]);
    expect(CourseOffering::pluck('id')->all())->toBe([$this->offering->id]);
    expect(CourseOffering::find($this->otherOffering->id))->toBeNull();
    expect(User::whereKey($this->student->id)->exists())->toBeTrue();
    expect(User::whereKey($this->otherStudent->id)->exists())->toBeFalse();
    app(AssignedDataAccess::class)->actor = null;
});

it('rejects direct reports and signed bulk copy selections outside the assigned department', function () {
    $this->get(route('evaluation.report', $this->otherOffering))->assertForbidden();
    $form = app(EvaluationTemplates::class)->create('A', $this->campus);
    $this->otherOffering->update(['form_id' => $form->id]);
    $submission = FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $this->otherOffering->id, 'data' => []]);
    expect(app(EvaluationCopies::class)->query($this->campus, $this->head)->whereKey($submission->id)->exists())->toBeFalse();
    expect(fn () => app(EvaluationCopies::class)->previewUrl($this->head, $this->campus, [$submission->id]))
        ->toThrow(HttpException::class);
});

it('rechecks assignment revocation when opening an existing print selection', function () {
    $form = app(EvaluationTemplates::class)->create('A', $this->campus);
    $this->offering->update(['form_id' => $form->id]);
    $submission = FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $this->offering->id, 'data' => []]);
    $url = app(EvaluationCopies::class)->previewUrl($this->head, $this->campus, [$submission->id]);
    $this->head->update(['instructor_department_id' => $this->otherDepartment->id]);
    $this->get($url)->assertForbidden();
});

it('keeps a campus administrator in the assigned campus and allows its departments', function () {
    $this->head->update(['instructor_department_id' => null]);
    DB::table('user_campus_access')->insert(['user_id' => $this->head->id, 'campus_id' => $this->campus->id, 'scope' => 'campus', 'created_at' => now(), 'updated_at' => now()]);
    $service = app(AssignedDataAccess::class);
    expect($service->departmentIds($this->head, $this->campus))->toBeNull();
    expect($service->apply(CourseOffering::query(), $this->head, $this->campus)->count())->toBe(2);
    $otherCampus = Campus::create(['name' => 'Another Campus', 'slug' => 'another', 'is_active' => true]);
    expect($service->apply(CourseOffering::query(), $this->head, $otherCampus)->count())->toBe(0);
});

it('applies the shared scope through the authenticated panel middleware', function () {
    $this->head->assignRole(Role::findOrCreate('department_staff', 'web'));
    $this->get(route('filament.admin.pages.student-evaluation-progress', ['tenant' => $this->campus->id]))
        ->assertOk()->assertSee($this->student->name)->assertDontSee($this->otherStudent->name);
    expect(app(AssignedDataAccess::class)->actor)->toBeNull();
});
