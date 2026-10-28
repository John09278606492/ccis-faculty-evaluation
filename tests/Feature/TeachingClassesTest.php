<?php

use App\Filament\Resources\TeachingClassResource\Pages\CreateTeachingClass;
use App\Filament\Resources\TeachingClassResource\Pages\EditTeachingClass;
use App\Filament\Resources\TeachingClassResource\Pages\ListTeachingClasses;
use App\Models\Campus;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\FormSubmission;
use App\Models\SchoolYear;
use App\Models\Semester;
use App\Models\User;
use App\Services\AssignedDataAccess;
use App\Services\EvaluationTemplates;
use App\Services\TeachingClasses;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    ['offering' => $this->offering, 'student' => $this->student] = evaluationFixture();
    $this->campus = $this->offering->campus;
    $this->department = $this->offering->course->department;
    $this->actor = $this->offering->instructor;
    $this->actor->campuses()->attach($this->campus);
    $this->actor->assignRole(Role::findOrCreate('instructor', 'web'));
    foreach (['view_any_course::offering', 'view_course::offering', 'create_course::offering', 'update_course::offering'] as $permission) {
        $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($this->actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    $this->data = ['semester_id' => $this->offering->semester_id, 'course_id' => $this->offering->course_id,
        'user_id' => $this->actor->id, 'section' => 'BSIT 3A', 'is_active' => true];
    $this->service = app(TeachingClasses::class);
});

it('creates and edits teaching classes through the actual resource pages', function () {
    Livewire::test(CreateTeachingClass::class)->fillForm($this->data)->call('create')->assertHasNoFormErrors();
    $class = CourseOffering::where('section', 'BSIT 3A')->sole();
    expect($class->teaching_department_id)->toBe($this->department->id)->and($class->form_id)->toBeNull();
    Livewire::test(EditTeachingClass::class, ['record' => $class->getRouteKey()])
        ->fillForm(['section' => 'BSIT 3B'])->call('save')->assertHasNoFormErrors();
    expect($class->fresh()->section)->toBe('BSIT 3B');
    Livewire::test(ListTeachingClasses::class)->assertSuccessful()->assertCanSeeTableRecords([$class])
        ->assertTableColumnExists('assigned_sections')->assertTableColumnDoesNotExist('evaluation_type_label')
        ->assertTableColumnDoesNotExist('ratings_count')->assertTableColumnDoesNotExist('evaluationForm.name');
});

it('allows a visiting faculty member while retaining the teaching course department scope', function () {
    $otherDepartment = Department::create(['campus_id' => $this->campus->id, 'code' => 'CTAS', 'name' => 'CTAS', 'is_active' => true]);
    $visitor = User::factory()->create(['is_instructor' => true, 'instructor_department_id' => $otherDepartment->id]);
    $visitor->campuses()->attach($this->campus);
    $class = $this->service->save($this->actor, $this->campus, [...$this->data, 'user_id' => $visitor->id]);
    expect($class->teaching_department_id)->toBe($this->department->id)->and($class->user_id)->toBe($visitor->id);
    expect(app(AssignedDataAccess::class)->apply(CourseOffering::query(), $this->actor, $this->campus)->whereKey($class)->exists())->toBeTrue();
});

it('refuses courses outside the actors department even when the campus matches', function () {
    $otherDepartment = Department::create(['campus_id' => $this->campus->id, 'code' => 'CTAS', 'name' => 'CTAS', 'is_active' => true]);
    $course = Course::create(['department_id' => $otherDepartment->id, 'code' => 'OTHER101', 'name' => 'Outside department', 'is_active' => true]);
    expect(fn () => $this->service->save($this->actor, $this->campus, [...$this->data, 'course_id' => $course->id]))
        ->toThrow(ValidationException::class);
    Livewire::test(CreateTeachingClass::class)->fillForm([...$this->data, 'course_id' => $course->id])
        ->call('create')->assertHasFormErrors(['course_id']);
    expect(CourseOffering::where('course_id', $course->id)->exists())->toBeFalse();
});

it('rejects forged semester course and faculty IDs from another campus', function (string $field) {
    $campus = Campus::create(['name' => 'Other', 'slug' => 'other', 'is_active' => true]);
    $department = Department::create(['campus_id' => $campus->id, 'code' => 'CCJ', 'name' => 'CCJ', 'is_active' => true]);
    $year = SchoolYear::create(['campus_id' => $campus->id, 'name' => '2026-2027', 'start_date' => '2026-06-01', 'end_date' => '2027-05-31']);
    $semester = Semester::create(['school_year_id' => $year->id, 'name' => 'First Semester', 'start_date' => '2026-06-01', 'end_date' => '2026-12-31']);
    $course = Course::create(['department_id' => $department->id, 'code' => 'FOREIGN101', 'name' => 'Other campus course', 'is_active' => true]);
    $faculty = User::factory()->create(['is_instructor' => true, 'instructor_department_id' => $department->id]);
    $faculty->campuses()->attach($campus);
    $forged = ['semester_id' => $semester->id, 'course_id' => $course->id, 'user_id' => $faculty->id];
    $before = CourseOffering::count();
    expect(fn () => $this->service->save($this->actor, $this->campus, [...$this->data, $field => $forged[$field]]))
        ->toThrow(ValidationException::class);
    Livewire::test(CreateTeachingClass::class)->fillForm([...$this->data, $field => $forged[$field]])
        ->call('create')->assertHasFormErrors([$field]);
    expect(CourseOffering::count())->toBe($before);
})->with(['semester_id', 'course_id', 'user_id']);

it('clears unconfirmed evaluators when a draft class is changed to another faculty', function () {
    $extra = User::factory()->create(['is_student' => false]);
    $extra->campuses()->attach($this->campus);
    $this->offering->supervisorEvaluators()->attach($extra);
    $faculty = User::factory()->create(['is_instructor' => true, 'instructor_department_id' => $this->department->id]);
    $faculty->campuses()->attach($this->campus);
    $this->service->save($this->actor, $this->campus, [...$this->data, 'user_id' => $faculty->id], $this->offering);
    $class = $this->offering->fresh();
    expect($class->students()->count())->toBe(0)->and($class->supervisorEvaluators()->count())->toBe(0)
        ->and($class->form_id)->toBeNull()->and($class->evaluator_selection)->toBeNull();
});

it('protects a faculty identity once an Annex B task has been prepared even before student responses', function () {
    $form = app(EvaluationTemplates::class)->create('B', $this->campus);
    $task = $this->offering->replicate();
    $task->fill(['course_id' => null, 'form_id' => $form->id, 'teaching_department_id' => $this->department->id]);
    $task->save();
    $faculty = User::factory()->create(['is_instructor' => true, 'instructor_department_id' => $this->department->id]);
    $faculty->campuses()->attach($this->campus);
    expect(fn () => $this->service->save($this->actor, $this->campus, [...$this->data, 'user_id' => $faculty->id], $this->offering))->toThrow(HttpException::class);
    expect($this->offering->fresh()->user_id)->toBe($this->actor->id);
});

it('refuses direct updates to a class outside the assigned department', function () {
    $department = Department::create(['campus_id' => $this->campus->id, 'code' => 'CTAS', 'name' => 'CTAS', 'is_active' => true]);
    $course = Course::create(['department_id' => $department->id, 'code' => 'OTHER101', 'name' => 'Outside department', 'is_active' => true]);
    $class = CourseOffering::create([...$this->data, 'course_id' => $course->id, 'teaching_department_id' => $department->id]);
    expect(fn () => $this->service->save($this->actor, $this->campus, $this->data, $class))->toThrow(HttpException::class);
    expect($class->fresh()->course_id)->toBe($course->id);
});

it('protects the period course and evaluatee after responses while allowing descriptive edits', function (string $field) {
    $submission = FormSubmission::create(['form_id' => $this->offering->form_id, 'course_offering_id' => $this->offering->id,
        'user_id' => $this->student->id, 'data' => ['teaching_quality' => 5]]);
    $originalData = $submission->fresh()->getRawOriginal();
    $semester = Semester::create(['school_year_id' => $this->offering->semester->school_year_id, 'name' => 'Second Semester',
        'start_date' => '2027-01-01', 'end_date' => '2027-05-31']);
    $course = Course::create(['department_id' => $this->department->id, 'code' => 'CS102', 'name' => 'Programming', 'is_active' => true]);
    $faculty = User::factory()->create(['is_instructor' => true, 'instructor_department_id' => $this->department->id]);
    $faculty->campuses()->attach($this->campus);
    $changed = ['semester_id' => $semester->id, 'course_id' => $course->id, 'user_id' => $faculty->id];
    expect(fn () => $this->service->save($this->actor, $this->campus, [...$this->data, $field => $changed[$field]], $this->offering))
        ->toThrow(HttpException::class);
    $this->service->save($this->actor, $this->campus, [...$this->data, 'section' => 'Corrected label'], $this->offering);
    expect($this->offering->fresh()->section)->toBe('Corrected label');
    expect($submission->fresh()->getRawOriginal())->toBe($originalData);
})->with(['semester_id', 'course_id', 'user_id']);
