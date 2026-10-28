<?php

use App\Filament\Resources\EvaluationCycleResource\Pages\CreateEvaluationCycle;
use App\Filament\Resources\EvaluationCycleResource\Pages\EditEvaluationCycle;
use App\Models\BackgroundTask;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\EvaluationCycle;
use App\Models\FormSubmission;
use App\Models\User;
use App\Services\AssignedDataAccess;
use App\Services\BackgroundTasks;
use App\Services\EvaluationCycles;
use App\Services\EvaluationDraftPreparation;
use App\Services\EvaluationSetupAutomation;
use App\Services\EvaluationTemplates;
use App\Services\FacultySupervisorEvaluators;
use App\Services\SupervisorAssignmentGaps;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
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
    foreach (['manage_evaluators_course::offering', 'update_course::offering'] as $p) {
        $this->actor->givePermissionTo(Permission::findOrCreate($p, 'web'));
    }
    Role::findOrCreate('supervisor', 'web');
    $this->head = User::factory()->create(['is_student' => false, 'is_instructor' => true]);
    $this->head->assignRole('supervisor');
    $this->head->campuses()->attach($this->campus);
    $this->chair = User::factory()->create(['is_student' => false]);
    $this->chair->assignRole('supervisor');
    $this->chair->campuses()->attach($this->campus);
    $this->department->update(['supervisor_id' => $this->head->id, 'chairperson_id' => $this->chair->id]);
    $this->a = app(EvaluationTemplates::class)->create('A', $this->campus);
    $this->a->departments()->attach($this->department);
    $this->b = app(EvaluationTemplates::class)->create('B', $this->campus);
    $this->b->departments()->attach($this->department);
    $this->class->update(['form_id' => null]);
    $this->data = ['name' => 'Automated setup', 'department_id' => $this->department->id, 'semester_id' => $this->class->semester_id,
        'student_form_id' => $this->a->id, 'supervisor_form_id' => $this->b->id, 'starts_at' => null, 'ends_at' => null, 'class_selection_mode' => 'prepared'];
    $this->actingAs($this->actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    Queue::fake();
});

function automationExtraClass(?User $faculty = null): CourseOffering
{
    $course = Course::create(['code' => 'CS'.(202 + Course::count()), 'name' => 'Second subject', 'department_id' => test()->department->id, 'is_active' => true]);
    $c = test()->class->replicate();
    $c->course_id = $course->id;
    $c->user_id = $faculty?->id ?? $c->user_id;
    $c->save();
    $c->students()->attach(test()->student);

    return $c;
}

it('automatically includes prepared subjects and sections and excludes incomplete inactive and recorded classes', function () {
    $second = automationExtraClass();
    $empty = $second->replicate();
    $empty->section = 'Empty';
    $empty->save();
    $inactive = $second->replicate();
    $inactive->is_active = false;
    $inactive->section = 'Inactive';
    $inactive->save();
    $inactive->students()->attach($this->student);
    $recorded = $second->replicate();
    $recorded->section = 'Historical';
    $recorded->save();
    FormSubmission::create(['course_offering_id' => $recorded->id, 'form_id' => $this->a->id, 'data' => ['criterion_1' => 4]]);
    $cycle = app(EvaluationCycles::class)->save($this->actor, $this->campus, $this->data);
    expect($cycle->assignments()->pluck('id')->sort()->values()->all())->toBe(collect([$this->class->id, $second->id])->sort()->values()->all())
        ->and($cycle->class_selection['mode'])->toBe('prepared')->and($cycle->status)->toBe('draft');
});

it('includes all prepared classes for chosen faculty without selecting each class', function () {
    $second = automationExtraClass();
    $otherFaculty = User::factory()->create(['is_instructor' => true]);
    $other = automationExtraClass($otherFaculty);
    $cycle = app(EvaluationCycles::class)->save($this->actor, $this->campus, [...$this->data, 'class_selection_mode' => 'faculty', 'faculty_ids' => [$this->class->user_id]]);
    expect($cycle->assignments()->pluck('id')->sort()->values()->all())->toBe(collect([$this->class->id, $second->id])->sort()->values()->all());
    expect(fn () => app(EvaluationCycles::class)->save($this->actor, $this->campus, [...$this->data, 'class_selection_mode' => 'faculty', 'faculty_ids' => [999999]]))->toThrow(ValidationException::class);
});

it('refreshes an automatic draft on save while preserving existing manual selections', function () {
    $service = app(EvaluationCycles::class);
    $cycle = $service->save($this->actor, $this->campus, $this->data);
    $second = automationExtraClass();
    $service->save($this->actor, $this->campus, $this->data, $cycle);
    expect($cycle->assignments()->count())->toBe(2);
    $service->save($this->actor, $this->campus, [...$this->data, 'class_selection_mode' => 'manual', 'assignment_ids' => [$this->class->id]], $cycle->fresh());
    expect($cycle->assignments()->pluck('id')->all())->toBe([$this->class->id])->and($second->fresh()->evaluation_cycle_id)->toBeNull();
});

it('excludes overlapping rosters rather than creating duplicate evaluations for one faculty and subject', function () {
    $duplicate = $this->class->replicate();
    $duplicate->section = 'B';
    $duplicate->save();
    $duplicate->students()->attach($this->student);
    $plan = app(EvaluationSetupAutomation::class)->plan($this->actor, $this->campus, $this->department->id, $this->class->semester_id);
    expect($plan['prepared'])->toHaveCount(0)->and(array_column($plan['excluded'], 'reason'))->toContain('Overlapping student rosters for the same faculty and subject');
});

it('reuses previously configured department instruments but leaves ambiguous new instrument choices for review', function () {
    $service = app(EvaluationSetupAutomation::class);
    expect($service->defaults($this->actor, $this->campus, $this->department->id, $this->class->semester_id)['student_form_id'])->toBe($this->a->id);
    $copy = app(EvaluationTemplates::class)->duplicate($this->a, $this->campus, 'Another draft');
    expect($service->defaults($this->actor, $this->campus, $this->department->id, $this->class->semester_id)['student_form_id'])->toBeNull();
    app(EvaluationCycles::class)->save($this->actor, $this->campus, $this->data);
    expect($service->defaults($this->actor, $this->campus, $this->department->id, $this->class->semester_id)['student_form_id'])->toBe($this->a->id);
});

it('uses a compact setup and suggests name instruments and prepared classes after department selection', function () {
    $page = Livewire::test(CreateEvaluationCycle::class)->set('data.department_id', $this->department->id)->assertHasNoFormErrors()
        ->assertFormSet(['class_selection_mode' => 'prepared', 'student_form_id' => $this->a->id, 'supervisor_form_id' => $this->b->id])
        ->assertSee('All prepared classes in this department');
    expect($page->get('data.name'))->toContain($this->department->code);
    $page->call('create')->assertHasNoFormErrors();
    expect(EvaluationCycle::sole()->assignments()->count())->toBe(1);
});

it('groups supervisor gaps by faculty without guessing who evaluates the department head', function () {
    $this->class->update(['user_id' => $this->head->id]);
    $second = automationExtraClass($this->head);
    $cycle = app(EvaluationCycles::class)->save($this->actor, $this->campus, $this->data);
    $service = app(SupervisorAssignmentGaps::class);
    $gaps = $service->gaps($this->actor, $this->campus, $cycle->id);
    expect($gaps)->toHaveCount(1)->and($gaps[0]['class_count'])->toBe(2)->and($gaps[0]['supervisor_ids'])->toBe([])
        ->and($this->class->supervisorEvaluators()->count())->toBe(0);
    $page = Livewire::test(EditEvaluationCycle::class, ['record' => $cycle->id])->mountAction('resolveSupervisorGaps');
    $itemKey = array_key_first($page->get('mountedActionsData.0.faculty_supervisors'));
    $page->set('mountedActionsData.0.faculty_supervisors.'.$itemKey.'.supervisor_ids', [$this->chair->id])->callMountedAction()->assertHasNoActionErrors();
    $task = BackgroundTask::where('kind', 'supervisor-resolution')->sole();
    app(BackgroundTasks::class)->run($task->id);
    expect($task->fresh()->status)->toBe('completed', $task->fresh()->error ?? '')->and($task->fresh()->result['assigned_classes'])->toBe(2)
        ->and($second->supervisorEvaluators()->pluck('users.id')->all())->toBe([$this->chair->id]);
});

it('rechecks grouped assignments skips existing individual choices and rejects self evaluation or foreign staff', function () {
    $cycle = app(EvaluationCycles::class)->save($this->actor, $this->campus, $this->data);
    $service = app(SupervisorAssignmentGaps::class);
    $payload = ['cycle_id' => $cycle->id, 'faculty_supervisors' => [['faculty_id' => $this->class->user_id, 'supervisor_ids' => [$this->head->id]]]];
    app(FacultySupervisorEvaluators::class)->save($this->actor, $this->class, [$this->chair->id]);
    expect($service->apply($this->actor, $this->campus, $payload))->toMatchArray(['assigned_classes' => 0, 'preserved_classes' => 1]);
    $payload['faculty_supervisors'][0]['supervisor_ids'] = [$this->class->user_id];
    expect(fn () => $service->apply($this->actor, $this->campus, $payload))->toThrow(ValidationException::class);
    $foreign = User::factory()->create(['is_student' => false]);
    $foreign->assignRole('supervisor');
    $payload['faculty_supervisors'][0]['supervisor_ids'] = [$foreign->id];
    expect(fn () => $service->apply($this->actor, $this->campus, $payload))->toThrow(ValidationException::class);
});

it('suggests consistent existing supervisor choices for the same faculty rather than assuming a hierarchy', function () {
    $this->class->update(['user_id' => $this->head->id]);
    $second = automationExtraClass($this->head);
    app(FacultySupervisorEvaluators::class)->save($this->actor, $this->class, [$this->chair->id]);
    $cycle = app(EvaluationCycles::class)->save($this->actor, $this->campus, $this->data);
    $gaps = app(SupervisorAssignmentGaps::class)->gaps($this->actor, $this->campus, $cycle->id);
    expect($gaps)->toHaveCount(1)->and($gaps[0]['class_count'])->toBe(1)->and($gaps[0]['supervisor_ids'])->toBe([$this->chair->id])->and($gaps[0]['suggested'])->toBeTrue();
});

it('closes only published evaluations with an expired closing date and leaves manual closing evaluations alone', function () {
    $service = app(EvaluationCycles::class);
    $manual = $service->save($this->actor, $this->campus, $this->data);
    $manual->update(['status' => 'published']);
    $due = $manual->replicate();
    $due->name = 'Due';
    $due->ends_at = now()->subMinute();
    $due->save();
    $future = $manual->replicate();
    $future->name = 'Future';
    $future->ends_at = now()->addDay();
    $future->save();
    $draft = $manual->replicate();
    $draft->name = 'Draft';
    $draft->status = 'draft';
    $draft->ends_at = now()->subDay();
    $draft->save();
    $this->artisan('classroom:evaluations-maintain')->assertSuccessful();
    expect($manual->fresh()->status)->toBe('published')->and($due->fresh()->status)->toBe('closed')->and($due->fresh()->closed_at)->not->toBeNull()
        ->and($future->fresh()->status)->toBe('published')->and($draft->fresh()->status)->toBe('draft');
    $this->artisan('classroom:evaluations-maintain')->assertSuccessful();
});

it('prepares department drafts in the background and repeating preparation keeps existing selections', function () {
    $task = app(BackgroundTasks::class)->enqueue($this->actor, $this->campus, 'evaluation-prepare', 'Prepare departments', ['department_ids' => [$this->department->id], 'semester_id' => $this->class->semester_id]);
    app(BackgroundTasks::class)->run($task->id);
    expect($task->fresh()->status)->toBe('completed', $task->fresh()->error ?? '')->and($task->fresh()->result['created'])->toBe(1);
    $cycle = EvaluationCycle::sole();
    expect($cycle->status)->toBe('draft')->and($cycle->class_selection['mode'])->toBe('prepared')->and($cycle->assignments()->count())->toBe(1);
    $again = app(EvaluationDraftPreparation::class)->prepare($this->actor, $this->campus, $this->class->semester_id, [$this->department->id]);
    expect($again['created'])->toBe(0)->and($again['existing'])->toBe(1)->and(EvaluationCycle::count())->toBe(1);
});

it('skips departments without configured instruments and rejects a department outside the operator access', function () {
    $other = Department::create(['campus_id' => $this->campus->id, 'name' => 'Other', 'code' => 'OTHER', 'is_active' => true]);
    $service = app(EvaluationDraftPreparation::class);
    $result = $service->prepare($this->actor, $this->campus, $this->class->semester_id, [$other->id]);
    expect($result['skipped'])->toBe(1)->and(EvaluationCycle::count())->toBe(0);
    $this->actor->removeRole('super_admin');
    $this->actor->update(['instructor_department_id' => $this->department->id]);
    expect(fn () => $service->prepare($this->actor->fresh(), $this->campus, $this->class->semester_id, [$other->id]))->toThrow(HttpException::class);
});

it('selects visiting teaching classes by their assigned teaching department without widening course access', function () {
    $other = Department::create(['campus_id' => $this->campus->id, 'name' => 'Other', 'code' => 'OTHER', 'is_active' => true]);
    $this->class->course->update(['department_id' => $other->id]);
    $this->class->update(['teaching_department_id' => $this->department->id]);
    $this->actor->removeRole('super_admin');
    $this->actor->update(['instructor_department_id' => $this->department->id]);
    app(AssignedDataAccess::class)->actor = $this->actor;
    $cycle = app(EvaluationCycles::class)->save($this->actor, $this->campus, $this->data);
    expect($cycle->assignments()->pluck('id')->all())->toBe([$this->class->id]);
    expect(app(AssignedDataAccess::class)->apply(Course::query(), $this->actor, $this->campus)->whereKey($this->class->course_id)->exists())->toBeFalse();
});

it('keeps an explicitly assigned other-department supervisor valid in a scoped department review', function () {
    $other = Department::create(['campus_id' => $this->campus->id, 'name' => 'Other', 'code' => 'OTHER', 'is_active' => true]);
    $this->head->update(['instructor_department_id' => $other->id]);
    $this->student->update(['student_department_id' => $this->department->id]);
    $this->class->supervisorEvaluators()->attach($this->head);
    $cycle = app(EvaluationCycles::class)->save($this->actor, $this->campus, $this->data);
    $this->actor->removeRole('super_admin');$this->actor->update(['instructor_department_id' => $this->department->id]);
    app(\App\Services\AssignedDataAccess::class)->actor = $this->actor;
    expect(app(EvaluationCycles::class)->readiness($cycle->fresh())['errors'])->toBe([]);
});

it('keeps manual-closing results private after the semester ends until the evaluation itself closes', function () {
    $this->class->supervisorEvaluators()->attach($this->head);
    $cycle = app(EvaluationCycles::class)->save($this->actor, $this->campus, $this->data);
    app(EvaluationCycles::class)->publish($this->actor, $cycle);
    $this->class->semester->update(['end_date' => now()->subDay()]);
    expect($this->class->fresh()->isEvaluationAvailable())->toBeTrue()
        ->and(\App\Services\EvaluationResultsRelease::isClosed($this->class->fresh()))->toBeFalse();
    app(EvaluationCycles::class)->close($this->actor, $cycle->fresh());
    expect(\App\Services\EvaluationResultsRelease::isClosed($this->class->fresh()))->toBeTrue();
});
