<?php

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
use App\Services\EvaluationClassAdditions;
use App\Services\EvaluationCycles;
use App\Services\EvaluationTemplates;
use App\Services\FacultySupervisorEvaluators;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    ['offering' => $this->original, 'student' => $this->student] = evaluationFixture();
    $this->campus = $this->original->campus;
    $this->department = $this->original->course->department;
    $this->actor = User::factory()->create(['is_student' => false]);
    $this->actor->assignRole('super_admin');
    $this->actor->campuses()->attach($this->campus);
    foreach (['manage_evaluators_course::offering', 'update_course::offering'] as $p) {
        $this->actor->givePermissionTo(Permission::findOrCreate($p, 'web'));
    }
    Role::findOrCreate('supervisor', 'web');
    $this->head = User::factory()->create(['is_student' => false]);
    $this->head->assignRole('supervisor');
    $this->head->campuses()->attach($this->campus);
    $this->department->update(['supervisor_id' => $this->head->id]);
    $this->a = app(EvaluationTemplates::class)->create('A', $this->campus);
    $this->b = app(EvaluationTemplates::class)->create('B', $this->campus);
    $this->a->departments()->attach($this->department);
    $this->b->departments()->attach($this->department);
    $this->actingAs($this->actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    Queue::fake();
    $this->original->update(['form_id' => null]);
    app(FacultySupervisorEvaluators::class)->save($this->actor, $this->original, [$this->head->id]);
    $this->cycle = app(EvaluationCycles::class)->save($this->actor, $this->campus, [
        'name' => 'Open evaluation', 'department_id' => $this->department->id, 'semester_id' => $this->original->semester_id,
        'student_form_id' => $this->a->id, 'supervisor_form_id' => $this->b->id,
        'starts_at' => null, 'ends_at' => null, 'assignment_ids' => [$this->original->id],
    ]);
    app(EvaluationCycles::class)->publish($this->actor, $this->cycle);
    $this->cycle->refresh();
    $this->late = latePreparedClass();
    $this->service = app(EvaluationClassAdditions::class);
});

function latePreparedClass(?User $faculty = null): CourseOffering
{
    $course = Course::create(['department_id' => test()->department->id, 'code' => 'NEW'.Course::count(), 'name' => 'New prepared subject', 'is_active' => true]);
    $class = CourseOffering::create(['semester_id' => test()->original->semester_id, 'course_id' => $course->id,
        'user_id' => $faculty?->id ?? test()->original->user_id, 'section' => 'BSIT 3A', 'is_active' => true,
        'evaluator_selection' => ['report_sections' => [test()->student->id => 'BSIT 3A']]]);
    $class->students()->attach(test()->student);

    return $class;
}

it('adds all selected prepared classes in the background without resaving existing records and is repeat safe', function () {
    $other = latePreparedClass(User::factory()->create(['is_instructor' => true]));
    $submission = FormSubmission::create(['form_id' => $this->a->id, 'course_offering_id' => $this->original->id,
        'data' => ['criterion_1' => 5, 'comments' => 'Keep this'], 'submitted_at' => now()]);
    $before = [$submission->fresh()->getAttributes(), $this->original->fresh()->getAttributes(), $this->cycle->fresh()->getAttributes(),
        DB::table('course_offering_student')->where('course_offering_id', $this->original->id)->get()->toJson(),
        DB::table('course_offering_supervisor')->where('course_offering_id', $this->original->id)->get()->toJson()];
    $preview = $this->service->preview($this->actor, $this->campus, $this->cycle->id);
    expect($preview['rows'])->toHaveCount(2)->and(array_column($preview['rows'], 'uses_default'))->toBe([true, true]);
    $task = $this->service->enqueue($this->actor, $this->campus, $this->cycle->id, $preview['token'], [$this->late->id, $other->id]);
    expect($this->late->fresh()->evaluation_cycle_id)->toBeNull();
    app(BackgroundTasks::class)->run($task->id);
    expect($task->fresh()->status)->toBe('completed', $task->fresh()->error ?? '')
        ->and($task->fresh()->result['added_classes'])->toBe(2)
        ->and($this->late->fresh()->form_id)->toBe($this->a->id)
        ->and($this->late->fresh()->isEvaluationAvailable())->toBeTrue()
        ->and($other->fresh()->evaluation_cycle_id)->toBe($this->cycle->id)
        ->and(CourseOffering::where('evaluation_cycle_id', $this->cycle->id)->whereNull('course_id')->where('user_id', $other->user_id)->sole()->form_id)->toBe($this->b->id);
    expect([$submission->fresh()->getAttributes(), $this->original->fresh()->getAttributes(), $this->cycle->fresh()->getAttributes(),
        DB::table('course_offering_student')->where('course_offering_id', $this->original->id)->get()->toJson(),
        DB::table('course_offering_supervisor')->where('course_offering_id', $this->original->id)->get()->toJson()])->toBe($before);
    expect($this->service->apply($this->actor, $this->campus, $task->payload)['added_classes'])->toBe(0);
    expect($this->service->plan($this->actor, $this->campus, $this->cycle->id)['rows'])->toBe([]);
});

it('preserves explicitly selected multiple supervisors instead of applying the default', function () {
    $second = User::factory()->create(['is_student' => false]);
    $second->assignRole('supervisor');
    $second->campuses()->attach($this->campus);
    app(FacultySupervisorEvaluators::class)->save($this->actor, $this->late, [$this->head->id, $second->id]);
    $plan = $this->service->plan($this->actor, $this->campus, $this->cycle->id);
    expect($plan['rows'][0]['uses_default'])->toBeFalse();
    $this->service->apply($this->actor, $this->campus, $plan);
    expect($this->late->supervisorEvaluators()->count())->toBe(2)
        ->and(CourseOffering::where('evaluation_cycle_id', $this->cycle->id)->whereNull('course_id')->count())->toBe(1);
});

it('rejects stale rosters defaults forms or classes claimed by another evaluation atomically', function (string $change) {
    $other = latePreparedClass();
    $plan = $this->service->plan($this->actor, $this->campus, $this->cycle->id);
    match ($change) {
        'roster' => $this->late->students()->detach(),
        'section' => $this->late->update(['evaluator_selection' => ['report_sections' => [$this->student->id => 'BSIT 3B']]]),
        'default' => $this->department->update(['supervisor_id' => null]),
        'form' => $this->b->update(['name' => 'Changed instrument']),
        'subject' => $this->late->course->update(['code' => 'Changed course']),
        'faculty' => $this->late->evaluatee->update(['name' => 'Changed faculty']),
        'claimed' => $this->late->update(['evaluation_cycle_id' => EvaluationCycle::create([
            'campus_id' => $this->campus->id, 'department_id' => $this->department->id, 'semester_id' => $this->original->semester_id, 'name' => 'Another evaluation', 'status' => 'draft',
        ])->id]),
    };
    expect(fn () => $this->service->apply($this->actor, $this->campus, $plan))->toThrow(ValidationException::class);
    expect($other->fresh()->evaluation_cycle_id)->toBeNull();
})->with(['roster', 'section', 'default', 'form', 'subject', 'faculty', 'claimed']);

it('rejects work after manual closure expiry or permission loss', function (string $change) {
    $plan = $this->service->plan($this->actor, $this->campus, $this->cycle->id);
    match ($change) {
        'closed' => app(EvaluationCycles::class)->close($this->actor, $this->cycle),
        'expired' => $this->cycle->update(['ends_at' => now()->subMinute()]),
        'permission' => $this->actor->removeRole('super_admin')->revokePermissionTo('update_course::offering'),
    };
    expect(fn () => $this->service->apply($this->actor, $this->campus, $plan))->toThrow(Exception::class);
    expect($this->late->fresh()->evaluation_cycle_id)->toBeNull();
})->with(['closed', 'expired', 'permission']);

it('does not offer empty inactive overlapping ambiguous or self-supervised classes', function () {
    $this->late->students()->detach();
    $inactive = latePreparedClass();
    $inactive->update(['is_active' => false]);
    $self = latePreparedClass();
    $self->evaluatee->campuses()->syncWithoutDetaching([$this->campus->id]);
    $self->evaluatee->assignRole('supervisor');
    $this->department->update(['supervisor_id' => $self->user_id]);
    $plan = $this->service->plan($this->actor, $this->campus, $this->cycle->id);
    expect($plan['rows'])->toBe([])->and($plan['excluded'])->toHaveCount(3);
    $this->department->update(['supervisor_id' => $this->head->id]);
    $duplicate = $this->original->replicate();
    $duplicate->evaluation_cycle_id = null;
    $duplicate->form_id = null;
    $duplicate->section = 'Another section';
    $duplicate->save();
    $duplicate->students()->attach($this->student);
    $plan = $this->service->plan($this->actor, $this->campus, $this->cycle->id);
    expect(array_column($plan['rows'], 'id'))->not->toContain($duplicate->id);
    $ambiguous = $self->replicate();
    $ambiguous->user_id = User::factory()->create(['is_instructor' => true])->id;
    $ambiguous->evaluator_selection = null;
    $ambiguous->save();
    $ambiguous->students()->attach($this->student);
    expect(array_column($this->service->plan($this->actor, $this->campus, $this->cycle->id)['rows'], 'id'))->not->toContain($ambiguous->id);
});

it('requires an actor-bound unexpired preview and only permits selected preview rows', function () {
    $preview = $this->service->preview($this->actor, $this->campus, $this->cycle->id);
    expect(fn () => $this->service->enqueue($this->actor, $this->campus, $this->cycle->id, $preview['token'], [$this->original->id]))->toThrow(ValidationException::class);
    $other = User::factory()->create(['is_student' => false]);
    $other->assignRole('super_admin');
    $other->campuses()->attach($this->campus);
    foreach (['manage_evaluators_course::offering', 'update_course::offering'] as $p) {
        $other->givePermissionTo($p);
    }
    expect(fn () => $this->service->enqueue($other, $this->campus, $this->cycle->id, $preview['token'], [$this->late->id]))->toThrow(ValidationException::class);
    $this->travel(31)->minutes();
    expect(fn () => $this->service->enqueue($this->actor, $this->campus, $this->cycle->id, $preview['token'], [$this->late->id]))->toThrow(ValidationException::class);
});

it('offers a same-page preview and bulk confirmation for published evaluations', function () {
    $page = Livewire::test(EditEvaluationCycle::class, ['record' => $this->cycle->id])
        ->assertActionVisible('addPreparedClasses')->mountAction('addPreparedClasses')->assertSee('1 newly prepared classes found.')
        ->assertSee('(department default)')->assertHasNoActionErrors();
    $page->callMountedAction()->assertHasNoActionErrors();
    expect(BackgroundTask::where('kind', 'evaluation-append')->count())->toBe(1)->and($this->late->fresh()->evaluation_cycle_id)->toBeNull();
});

it('only adds chosen preview rows and deduplicates repeat confirmations', function () {
    $other = latePreparedClass();
    $preview = $this->service->preview($this->actor, $this->campus, $this->cycle->id);
    $first = $this->service->enqueue($this->actor, $this->campus, $this->cycle->id, $preview['token'], [$this->late->id]);
    $repeat = $this->service->enqueue($this->actor, $this->campus, $this->cycle->id, $preview['token'], [$this->late->id]);
    expect($repeat->id)->toBe($first->id);
    app(BackgroundTasks::class)->run($first->id);
    expect($first->fresh()->status)->toBe('completed')->and($this->late->fresh()->evaluation_cycle_id)->toBe($this->cycle->id)
        ->and($other->fresh()->evaluation_cycle_id)->toBeNull();
});

it('limits additions to the teaching department and refuses other department managers', function () {
    $otherDepartment = Department::create(['campus_id' => $this->campus->id, 'name' => 'Other College', 'code' => 'OTHER', 'is_active' => true]);
    $other = latePreparedClass();
    $other->update(['teaching_department_id' => $otherDepartment->id]);
    expect(array_column($this->service->plan($this->actor, $this->campus, $this->cycle->id)['rows'], 'id'))->toBe([$this->late->id]);
    $manager = User::factory()->create(['is_student' => false, 'instructor_department_id' => $otherDepartment->id]);
    $manager->assignRole('supervisor');
    $manager->campuses()->attach($this->campus);
    $manager->givePermissionTo(['manage_evaluators_course::offering', 'update_course::offering']);
    expect(fn () => $this->service->preview($manager, $this->campus, $this->cycle->id))->toThrow(ModelNotFoundException::class);
});

it('shows an empty preview with disabled confirmation and hides additions after closure', function () {
    $this->late->students()->detach();
    $page = Livewire::test(EditEvaluationCycle::class, ['record' => $this->cycle->id])->mountAction('addPreparedClasses')
        ->assertSee('No new classes are ready.');
    expect($page->instance()->getMountedAction()->getModalSubmitAction()->isDisabled())->toBeTrue();
    app(EvaluationCycles::class)->close($this->actor, $this->cycle);
    Livewire::test(EditEvaluationCycle::class, ['record' => $this->cycle->id])->assertActionHidden('addPreparedClasses');
});

it('creates Annex B tasks for visiting faculty with an explicitly chosen supervisor from another department', function () {
    $otherDepartment = Department::create(['campus_id' => $this->campus->id, 'name' => 'Visiting College', 'code' => 'VISIT', 'is_active' => true]);
    $faculty = User::factory()->create(['is_instructor' => true, 'instructor_department_id' => $otherDepartment->id]);
    $faculty->campuses()->attach($this->campus);
    $head = User::factory()->create(['is_student' => false, 'instructor_department_id' => $otherDepartment->id]);
    $head->assignRole('supervisor');
    $head->campuses()->attach($this->campus);
    $class = latePreparedClass($faculty);
    app(FacultySupervisorEvaluators::class)->save($this->actor, $class, [$head->id]);
    $this->actor->removeRole('super_admin')->assignRole('supervisor');
    $this->actor->update(['instructor_department_id' => $this->department->id]);
    app(AssignedDataAccess::class)->actor = $this->actor;
    expect(User::whereKey($head->id)->exists())->toBeFalse();
    $plan = $this->service->plan($this->actor, $this->campus, $this->cycle->id);
    $plan['rows'] = array_values(array_filter($plan['rows'], fn ($row) => $row['id'] === $class->id));
    expect($plan['rows'])->toHaveCount(1);
    $this->service->apply($this->actor, $this->campus, $plan);
    $task = CourseOffering::where('evaluation_cycle_id', $this->cycle->id)->where('user_id', $faculty->id)->whereNull('course_id')->sole();
    expect($task->form_id)->toBe($this->b->id)
        ->and(app(FacultySupervisorEvaluators::class)->taskIds($head, $this->campus)->where('task.id', $task->id)->exists())->toBeTrue();
});

it('flags late classes whose students already rate the subject in the evaluation and leaves full duplicates unticked', function () {
    $copy = latePreparedClass(User::factory()->create(['is_instructor' => true]));
    $copy->update(['course_id' => $this->original->course_id]);
    $plan = $this->service->plan($this->actor, $this->campus, $this->cycle->id);
    $rows = collect($plan['rows'])->keyBy('id');
    expect($rows[$this->late->id]['already_evaluating'])->toBe(0);
    if ($rows->has($copy->id)) {
        expect($rows[$copy->id]['already_evaluating'])->toBe(1);
    }
    Livewire::test(EditEvaluationCycle::class, ['record' => $this->cycle->id])->mountAction('addPreparedClasses')
        ->assertActionDataSet(['class_ids' => [$this->late->id]]);
});

it('separates leftover copies whose students already rate the subject from classes that need action', function () {
    $copy = $this->original->replicate();
    $copy->evaluation_cycle_id = null;
    $copy->form_id = null;
    $copy->section = 'Another section';
    $copy->save();
    $copy->students()->attach($this->student);
    $this->late->students()->detach();
    $plan = $this->service->plan($this->actor, $this->campus, $this->cycle->id);
    $rows = collect($plan['excluded'])->keyBy('id');
    expect($rows[$copy->id]['covered'])->toBeTrue()->and($rows[$this->late->id]['covered'])->toBeFalse();
    Livewire::test(EditEvaluationCycle::class, ['record' => $this->cycle->id])->mountAction('addPreparedClasses')
        ->assertSee('1 classes need action')->assertSee('1 leftover copies need no action');
});
