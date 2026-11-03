<?php

use App\Filament\Resources\DepartmentResource\Pages\EditDepartment;
use App\Filament\Resources\EvaluationCycleResource\Pages\EditEvaluationCycle;
use App\Models\BackgroundTask;
use App\Models\Campus;
use App\Models\Department;
use App\Models\EvaluationCycle;
use App\Models\User;
use App\Services\AssignedDataAccess;
use App\Services\BackgroundTasks;
use App\Services\DepartmentSupervisorAssignments;
use App\Services\EvaluationCycles;
use App\Services\EvaluationTemplates;
use App\Services\FacultySupervisorEvaluators;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
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
    foreach (['manage_evaluators_course::offering', 'update_course::offering', 'view_any_department', 'view_department', 'update_department'] as $p) {
        $this->actor->givePermissionTo(Permission::findOrCreate($p, 'web'));
    }
    Role::findOrCreate('supervisor', 'web');
    $this->head = User::factory()->create(['is_student' => false]);
    $this->head->assignRole('supervisor');
    $this->head->campuses()->attach($this->campus);
    $this->extra = User::factory()->create(['is_student' => false]);
    $this->extra->assignRole('supervisor');
    $this->extra->campuses()->attach($this->campus);
    $this->department->update(['supervisor_id' => $this->head->id]);
    $this->actingAs($this->actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    $this->payload = ['department_id' => $this->department->id, 'semester_id' => $this->class->semester_id, 'supervisor_id' => $this->head->id];
    $this->bulk = app(DepartmentSupervisorAssignments::class);
    Queue::fake();
});

it('fills only missing supervisors and preserves one or more individual choices on repeated bulk runs', function () {
    $other = $this->class->replicate();
    $other->section = 'Other';
    $other->save();
    app(FacultySupervisorEvaluators::class)->save($this->actor, $other, [$this->head->id, $this->extra->id]);
    $result = $this->bulk->apply($this->actor, $this->campus, $this->payload);
    expect($result['assigned_classes'])->toBe(1)->and($result['preserved_classes'])->toBe(1)
        ->and($this->class->supervisorEvaluators()->pluck('users.id')->all())->toBe([$this->head->id])
        ->and($other->supervisorEvaluators()->count())->toBe(2)->and($this->class->students()->count())->toBe(1);
    app(FacultySupervisorEvaluators::class)->save($this->actor, $this->class, [$this->extra->id]);
    expect($this->bulk->apply($this->actor, $this->campus, $this->payload)['assigned_classes'])->toBe(0)
        ->and($this->class->supervisorEvaluators()->pluck('users.id')->all())->toBe([$this->extra->id]);
});

it('uses the teaching department for visiting faculty and skips self evaluations and closed cycles', function () {
    $host = Department::create(['campus_id' => $this->campus->id, 'name' => 'Other', 'code' => 'OTHER', 'is_active' => true]);
    $this->class->evaluatee->update(['instructor_department_id' => $host->id]);
    $self = $this->class->replicate();
    $self->user_id = $this->head->id;
    $self->section = 'Self';
    $self->save();
    $closed = $this->class->replicate();
    $closed->section = 'Closed';
    $closed->evaluation_cycle_id = EvaluationCycle::create(['name' => 'Closed', 'campus_id' => $this->campus->id, 'department_id' => $this->department->id, 'semester_id' => $this->class->semester_id, 'status' => 'closed'])->id;
    $closed->save();
    $result = $this->bulk->apply($this->actor, $this->campus, $this->payload);
    expect($result['assigned_classes'])->toBe(1)->and($result['self_evaluation_skipped'])->toBe(1)
        ->and($self->supervisorEvaluators()->count())->toBe(0)->and($closed->supervisorEvaluators()->count())->toBe(0);
});

it('rejects other campuses departments outside access students and changed department defaults', function () {
    $foreign = Campus::create(['name' => 'Foreign', 'slug' => 'foreign', 'is_active' => true]);
    expect(fn () => $this->bulk->apply($this->actor, $foreign, $this->payload))->toThrow(HttpException::class);
    $this->department->update(['supervisor_id' => $this->extra->id]);
    expect(fn () => $this->bulk->apply($this->actor, $this->campus, $this->payload))->toThrow(ValidationException::class);
    $this->department->update(['supervisor_id' => $this->head->id]);
    $this->actor->removeRole('super_admin');
    expect(fn () => $this->bulk->apply($this->actor, $this->campus, $this->payload))->toThrow(HttpException::class);
    expect($this->bulk->allowed($this->student, $this->campus))->toBeFalse();
});

it('rechecks a queued assignment when the department supervisor changes before execution', function () {
    $task = $this->bulk->enqueue($this->actor, $this->campus, $this->department->id, $this->class->semester_id);
    $this->department->update(['supervisor_id' => $this->extra->id]);
    app(BackgroundTasks::class)->run($task->id);
    expect($task->fresh()->status)->toBe('failed')->and($this->class->supervisorEvaluators()->count())->toBe(0);
});

it('preserves supervisors from other departments even when their accounts are outside the operator user scope', function () {
    $other = Department::create(['campus_id' => $this->campus->id, 'name' => 'Other', 'code' => 'OTHER', 'is_active' => true]);
    $this->extra->update(['instructor_department_id' => $other->id]);
    $this->class->supervisorEvaluators()->attach($this->extra);
    $this->actor->removeRole('super_admin');
    $this->actor->update(['instructor_department_id' => $this->department->id]);
    app(AssignedDataAccess::class)->actor = $this->actor;
    $result = $this->bulk->apply($this->actor, $this->campus, $this->payload);
    expect($result['assigned_classes'])->toBe(0)->and($result['preserved_classes'])->toBe(1)
        ->and(DB::table('course_offering_supervisor')->where('course_offering_id', $this->class->id)->pluck('user_id')->all())->toBe([$this->extra->id]);
});

it('queues missing supervisor assignment when saving department leadership with bulk enabled', function () {
    Livewire::test(EditDepartment::class, ['record' => $this->department->id])
        ->fillForm(['supervisor_id' => $this->extra->id, 'fill_missing_supervisors' => true, 'supervisor_academic_period' => $this->class->semester_id])
        ->call('save')->assertHasNoFormErrors();
    $task = BackgroundTask::where('kind', 'supervisor-bulk')->sole();
    expect($task->payload['supervisor_id'])->toBe($this->extra->id)->and($this->class->supervisorEvaluators()->count())->toBe(0);
    app(BackgroundTasks::class)->run($task->id);
    expect($task->fresh()->status)->toBe('completed', $task->fresh()->error ?? '')->and($this->class->supervisorEvaluators()->pluck('users.id')->all())->toBe([$this->extra->id]);
});

it('fixes cycle readiness in the background and creates working Annex B tasks on publication', function () {
    $a = app(EvaluationTemplates::class)->create('A', $this->campus);
    $b = app(EvaluationTemplates::class)->create('B', $this->campus);
    $service = app(EvaluationCycles::class);
    $cycle = $service->save($this->actor, $this->campus, ['name' => 'Bulk review', 'department_id' => $this->department->id,
        'semester_id' => $this->class->semester_id, 'student_form_id' => $a->id, 'supervisor_form_id' => $b->id, 'starts_at' => null, 'ends_at' => null, 'assignment_ids' => [$this->class->id]]);
    expect($service->readiness($cycle)['errors'])->not->toBeEmpty();
    Livewire::test(EditEvaluationCycle::class, ['record' => $cycle->id])->callAction(['review', 'departmentSupervisorsInReview'])->assertHasNoActionErrors();
    $task = BackgroundTask::where('kind', 'supervisor-bulk')->sole();
    app(BackgroundTasks::class)->run($task->id);
    expect($task->fresh()->status)->toBe('completed', $task->fresh()->error ?? '')->and($service->readiness($cycle->fresh())['errors'])->toBe([]);
    Livewire::test(EditEvaluationCycle::class, ['record' => $cycle->id])->mountAction('review')->assertSee('Use Department Supervisor');
    $service->publish($this->actor, $cycle->fresh());
    $annexB = $cycle->assignments()->where('evaluation_type', 'SEF')->sole();
    expect(app(FacultySupervisorEvaluators::class)->taskIds($this->head, $this->campus)->where('task.id', $annexB->id)->exists())->toBeTrue();
    $this->actingAs($this->head)->get(route('evaluate.course-offering', $annexB))->assertOk();
    $this->actingAs($this->student)->get(route('evaluate.course-offering', $this->class))->assertOk();
});
