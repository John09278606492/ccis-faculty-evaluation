<?php

use App\Filament\Resources\EvaluationCycleResource;
use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\User;
use App\Services\AcademicPeriod;
use App\Services\EvaluationCycles;
use App\Services\EvaluationTemplates;
use App\Services\SupervisorEvaluationAssignments;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

function cycleFixture(): array
{
    $fixture = evaluationFixture();
    $offering = $fixture['offering'];
    $campus = $offering->campus;
    $department = $offering->course->department;
    $head = User::factory()->create(['is_student' => false, 'instructor_department_id' => $department->id]);
    $head->campuses()->attach($campus);
    $head->assignRole(Role::findOrCreate('cycle_manager', 'web'));
    foreach (['manage_evaluators_course::offering', 'update_course::offering'] as $permission) {
        $head->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $department->update(['supervisor_id' => $head->id, 'chairperson_id' => $head->id]);
    $studentForm = app(EvaluationTemplates::class)->create('A', $campus);
    $supervisorForm = app(EvaluationTemplates::class)->create('B', $campus);
    $studentForm->departments()->attach($department);
    $supervisorForm->departments()->attach($department);
    $offering->update(['form_id' => $studentForm->id]);
    $offering->supervisorEvaluators()->attach($head);
    $data = ['name' => 'Department evaluation', 'department_id' => $department->id,
        'semester_id' => $offering->semester_id, 'student_form_id' => $studentForm->id,
        'supervisor_form_id' => $supervisorForm->id, 'starts_at' => now()->subHour(),
        'ends_at' => now()->addDays(7), 'assignment_ids' => [$offering->id]];

    return [...$fixture, ...compact('campus', 'department', 'head', 'studentForm', 'supervisorForm', 'data')];
}

it('runs draft readiness publish close and archive with explicit Annex B tasks', function () {
    extract(cycleFixture());
    $service = app(EvaluationCycles::class);
    $cycle = $service->save($head, $campus, $data);
    expect($cycle->status)->toBe('draft');
    expect($service->readiness($cycle))->toMatchArray(['errors' => [], 'classes' => 1, 'students' => 1, 'unique_students' => 1, 'heads' => 1]);
    $this->actingAs($student)->get(route('evaluate.course-offering', $offering))->assertForbidden();
    expect(CourseOffering::where('evaluation_cycle_id', $cycle->id)->count())->toBe(1);
    $service->publish($head, $cycle);
    $cycle->refresh();
    expect($cycle->status)->toBe('published');
    expect($cycle->published_by)->toBe($head->id);
    expect($studentForm->fresh()->isLocked())->toBeTrue();
    expect($supervisorForm->fresh()->isLocked())->toBeTrue();
    $tasks = $cycle->assignments()->where('evaluation_type', 'SEF')->get();
    expect($tasks)->toHaveCount(1);
    expect($tasks->first())->course_id->toBeNull()->teaching_department_id->toBe($department->id)->form_id->toBe($supervisorForm->id);
    $this->get(route('evaluate.course-offering', $offering))->assertOk();
    $this->actingAs($head)->get(route('evaluate.course-offering', $tasks->first()))->assertOk();
    app(SupervisorEvaluationAssignments::class)->prepare($head, $campus, $cycle);
    expect($cycle->assignments()->where('evaluation_type', 'SEF')->count())->toBe(1);
    $service->close($head, $cycle);
    $this->actingAs($student)->get(route('evaluate.course-offering', $offering))->assertForbidden();
    expect($cycle->fresh()->status)->toBe('closed');
    $service->close($head, $cycle->fresh(), archive: true);
    expect($cycle->fresh()->status)->toBe('archived');
    expect($cycle->assignments()->count())->toBe(2);
});

it('saves the current setup before reviewing and waits for explicit confirmation before opening', function () {
    extract(cycleFixture());
    $cycle = app(EvaluationCycles::class)->save($head, $campus, $data);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($campus, isQuiet: true);
    $this->actingAs($head);
    $page = \Livewire\Livewire::test(\App\Filament\Resources\EvaluationCycleResource\Pages\EditEvaluationCycle::class, ['record' => $cycle->id])
        ->fillForm(['name' => 'Reviewed current edits'])->mountAction('review')->assertHasNoErrors();
    expect($cycle->fresh()->name)->toBe('Reviewed current edits')->and($cycle->fresh()->status)->toBe('draft')
        ->and($cycle->assignments()->where('evaluation_type', 'SEF')->count())->toBe(0);
    $page->callMountedAction()->assertHasNoErrors();
    expect($cycle->fresh()->status)->toBe('published')->and($cycle->assignments()->where('evaluation_type', 'SEF')->count())->toBe(1);
});

it('requires readiness and correct lifecycle transitions before publication', function () {
    extract(cycleFixture());
    $service = app(EvaluationCycles::class);
    $cycle = $service->save($head, $campus, $data);
    $offering->students()->detach();
    expect(fn () => $service->publish($head, $cycle))->toThrow(ValidationException::class);
    expect($cycle->fresh()->status)->toBe('draft');
    expect($studentForm->fresh()->published_at)->toBeNull();
    expect(fn () => $service->close($head, $cycle, archive: true))->toThrow(HttpException::class);
    $offering->students()->attach($student);
    $service->publish($head, $cycle);
    expect(fn () => $service->publish($head, $cycle->fresh()))->toThrow(HttpException::class);
    expect(fn () => $service->save($head, $campus, $data, $cycle->fresh()))->toThrow(HttpException::class);
});

it('scopes cycle management to an assigned department and campus even with management permissions', function () {
    extract(cycleFixture());
    $service = app(EvaluationCycles::class);
    $cycle = $service->save($head, $campus, $data);
    $other = Department::create(['campus_id' => $campus->id, 'code' => 'CTAS', 'name' => 'CTAS', 'is_active' => true]);
    expect(fn () => $service->save($head, $campus, [...$data, 'department_id' => $other->id]))->toThrow(ValidationException::class);
    $outsider = User::factory()->create(['is_student' => false]);
    $outsider->campuses()->attach($campus);
    $outsider->givePermissionTo(['manage_evaluators_course::offering', 'update_course::offering']);
    expect($service->allowed($outsider, $campus))->toBeFalse();
    expect($service->query($outsider, $campus)->count())->toBe(0);
    expect(fn () => $service->publish($outsider, $cycle))->toThrow(HttpException::class);
    $otherCampus = Campus::create(['name' => 'Other', 'slug' => 'other', 'is_active' => true]);
    expect($service->allowed($head, $otherCampus))->toBeFalse();
    $this->actingAs($head);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($campus, isQuiet: true);
    expect(EvaluationCycleResource::canViewAny())->toBeTrue();
    $this->actingAs($outsider);
    expect(EvaluationCycleResource::canViewAny())->toBeFalse();
});

it('prepares a separate supervisor task for each cycle in the same semester', function () {
    extract(cycleFixture());
    $service = app(EvaluationCycles::class);
    $first = $service->save($head, $campus, $data);
    $service->publish($head, $first);
    $nextClass = $offering->fresh()->replicate();
    $nextClass->fill(['evaluation_cycle_id' => null, 'section' => 'Second cycle']);
    $nextClass->save();
    $nextClass->students()->attach($student);
    $nextClass->supervisorEvaluators()->sync([$head->id]);
    $second = $service->save($head, $campus, [...$data, 'name' => 'Follow-up cycle', 'assignment_ids' => [$nextClass->id]]);
    $service->publish($head, $second);
    expect($first->assignments()->where('evaluation_type', 'SEF')->count())->toBe(1);
    expect($second->assignments()->where('evaluation_type', 'SEF')->count())->toBe(1);
});

it('keeps the selected academic period separate for each campus and rejects foreign semesters', function () {
    extract(cycleFixture());
    AcademicPeriod::select($campus, $offering->semester_id);
    expect(AcademicPeriod::current($campus))->toBe($offering->semester_id);
    $otherCampus = Campus::create(['name' => 'Other', 'slug' => 'other', 'is_active' => true]);
    expect(fn () => AcademicPeriod::select($otherCampus, $offering->semester_id))->toThrow(ValidationException::class);
    expect(AcademicPeriod::current($otherCampus))->toBeNull();
    expect(AcademicPeriod::current($campus))->toBe($offering->semester_id);
});

it('requires rating criteria rather than section headers before a cycle can publish', function () {
    extract(cycleFixture());
    $studentForm->fields()->delete();
    $studentForm->fields()->create(['name' => 'header', 'label' => 'Teaching', 'type' => 'section_header']);
    $service = app(EvaluationCycles::class);
    $cycle = $service->save($head, $campus, $data);
    expect($service->readiness($cycle)['errors'])->not->toBeEmpty();
    expect(fn () => $service->publish($head, $cycle))->toThrow(ValidationException::class);
});

it('requires a supervisor other than the faculty being evaluated', function () {
    extract(cycleFixture());
    $faculty = $offering->evaluatee;
    $faculty->campuses()->attach($campus);
    $faculty->assignRole(Role::findOrCreate('instructor', 'web'));
    $offering->supervisorEvaluators()->sync([$faculty->id]);
    $service = app(EvaluationCycles::class);
    $cycle = $service->save($head, $campus, $data);
    expect($service->readiness($cycle)['errors'])->not->toBeEmpty();
    expect(fn () => $service->publish($head, $cycle))->toThrow(ValidationException::class);
    $offering->supervisorEvaluators()->sync([$head->id]);
    expect($service->readiness($cycle->fresh())['errors'])->toBeEmpty();
});
