<?php

use App\Filament\Pages\SupervisorEvaluations;
use App\Filament\Resources\CourseOfferingResource\RelationManagers\StudentsRelationManager;
use App\Filament\Resources\TeachingClassResource\Pages\EditTeachingClass;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\StudentAcademicRecord;
use App\Models\User;
use App\Services\AcademicSections;
use App\Services\AssignedDataAccess;
use App\Services\EvaluationAccessService;
use App\Services\EvaluationCycles;
use App\Services\EvaluationTemplates;
use App\Services\FacultySupervisorEvaluators;
use App\Services\SupervisorEvaluationAssignments;
use App\Services\WorkspaceSummary;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

function facultySupervisorFixture(): array
{
    $fixture = evaluationFixture();
    $offering = $fixture['offering'];
    $campus = $offering->campus;
    $department = $offering->course->department;
    $host = Department::create(['campus_id' => $campus->id, 'name' => 'CTAS', 'code' => 'CTAS', 'is_active' => true]);
    $owner = User::factory()->create(['is_student' => false]);
    $owner->assignRole('super_admin');
    $owner->campuses()->attach($campus);
    foreach (['manage_evaluators_course::offering', 'update_course::offering', 'view_course::offering', 'manage_academic_students', 'view_academic_students', 'page_EvaluationResults'] as $permission) {
        $owner->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $extra = User::factory()->create(['is_student' => false, 'instructor_department_id' => $host->id]);
    $extra->assignRole(Role::findOrCreate('supervisor', 'web'));
    $extra->campuses()->attach($campus);
    $head = User::factory()->create(['is_student' => false]);
    $head->assignRole('supervisor');
    $head->campuses()->attach($campus);
    $department->update(['supervisor_id' => $head->id]);
    $a = app(EvaluationTemplates::class)->create('A', $campus);
    $b = app(EvaluationTemplates::class)->create('B', $campus);
    $a->departments()->attach($department);
    $b->departments()->attach($department);
    $offering->update(['form_id' => $a->id]);
    $data = ['name' => 'Faculty-specific supervisors', 'department_id' => $department->id, 'semester_id' => $offering->semester_id,
        'student_form_id' => $a->id, 'supervisor_form_id' => $b->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDays(2), 'assignment_ids' => [$offering->id]];

    return [...$fixture, ...compact('campus', 'department', 'host', 'owner', 'extra', 'head', 'a', 'b', 'data')];
}

it('assigns selected supervisors only to the selected faculty and accepts independent Annex B responses', function () {
    extract(facultySupervisorFixture());
    app(FacultySupervisorEvaluators::class)->save($owner, $offering, [$head->id, $extra->id]);
    $cycles = app(EvaluationCycles::class);
    $cycle = $cycles->save($owner, $campus, $data);
    $cycles->publish($owner, $cycle);
    $task = $cycle->assignments()->where('evaluation_type', 'SEF')->sole();
    $other = $offering->fresh()->replicate();
    $other->user_id = User::factory()->create(['is_student' => false, 'is_instructor' => true, 'instructor_department_id' => $department->id])->id;
    $other->save();
    $other->supervisorEvaluators()->sync([$head->id]);
    app(SupervisorEvaluationAssignments::class)->prepareSource($other);
    $otherTask = $cycle->assignments()->where('evaluation_type', 'SEF')->where('user_id', $other->user_id)->sole();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($campus, isQuiet: true);
    $this->actingAs($extra);
    app(AssignedDataAccess::class)->actor = $extra;
    expect(app(AssignedDataAccess::class)->departmentIds($extra, $campus))->toBe([$host->id]);
    Livewire::test(SupervisorEvaluations::class)->assertCanSeeTableRecords([$task])->assertCanNotSeeTableRecords([$otherTask]);
    $this->get(route('evaluate.course-offering', $otherTask))->assertNotFound();
    $this->get(route('evaluate.course-offering', $task))->assertOk();
    $answers = $b->fields()->pluck('name')->mapWithKeys(fn ($name) => [$name => 4])->all();
    $this->post(route('evaluate.store', $task), ['fields' => $answers])->assertRedirect(route('evaluate.success', $task));
    $this->get(route('evaluate.course-offering', $task))->assertOk()->assertViewHas('alreadySubmitted', true);
    $this->post(route('evaluate.store', $task), ['fields' => $answers])->assertSessionHas('error');
    app(AssignedDataAccess::class)->actor = null;
    $this->actingAs($head)->post(route('evaluate.store', $task), ['fields' => $answers])->assertRedirect(route('evaluate.success', $task));
    expect($task->formSubmissions()->count())->toBe(2);
    $this->actingAs($extra);
    app(AssignedDataAccess::class)->actor = $extra;
    Livewire::test(SupervisorEvaluations::class)->assertSee('Completed');
    expect(app(WorkspaceSummary::class)->for($extra, $campus)['cards'][0]['value'])->toBe(0);
    expect(app(AssignedDataAccess::class)->apply(User::query(), $extra, $campus)->whereKey($student->id)->exists())->toBeFalse();
    expect(app(AssignedDataAccess::class)->apply(Department::query(), $extra, $campus)->whereKey($department->id)->exists())->toBeFalse();
});

it('supports a same-campus supervisor without a department profile without granting department access', function () {
    extract(facultySupervisorFixture());
    $a->update(['is_active' => true]);
    $extra->update(['instructor_department_id' => null]);
    app(FacultySupervisorEvaluators::class)->save($owner, $offering, [$extra->id]);
    app(SupervisorEvaluationAssignments::class)->prepareSource($offering);
    $task = CourseOffering::where('evaluation_type', 'SEF')->sole();
    $this->actingAs($extra);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($campus, isQuiet: true);
    app(AssignedDataAccess::class)->actor = $extra;
    expect(app(AssignedDataAccess::class)->departmentIds($extra, $campus))->toBe([]);
    Livewire::test(SupervisorEvaluations::class)->assertCanSeeTableRecords([$task]);
    $this->get(route('evaluate.course-offering', $task))->assertOk();
});

it('removes faculty-specific authority immediately and retains submitted results', function () {
    extract(facultySupervisorFixture());
    $a->update(['is_active' => true]);
    $service = app(FacultySupervisorEvaluators::class);
    $service->save($owner, $offering, [$extra->id]);
    $task = CourseOffering::where('evaluation_type', 'SEF')->sole();
    $answers = $task->evaluationForm->fields()->pluck('name')->mapWithKeys(fn ($name) => [$name => 5])->all();
    $this->actingAs($extra)->post(route('evaluate.store', $task), ['fields' => $answers])->assertRedirect();
    $service->save($owner, $offering, []);
    $this->get(route('evaluate.course-offering', $task))->assertForbidden();
    expect($task->formSubmissions()->count())->toBe(1)->and($service->taskIds($extra, $campus)->count())->toBe(0);
});

it('rejects other-campus staff, students, the evaluated faculty and unauthorized editors', function () {
    extract(facultySupervisorFixture());
    $foreign = User::factory()->create(['is_student' => false]);
    $foreign->assignRole('supervisor');
    $service = app(FacultySupervisorEvaluators::class);
    foreach ([$student->id, $foreign->id, $offering->user_id] as $invalid) {
        expect(fn () => $service->save($owner, $offering, [$invalid]))->toThrow(ValidationException::class);
    }
    expect(fn () => $service->save($extra, $offering, [$extra->id]))->toThrow(HttpException::class);
    expect($offering->supervisorEvaluators()->count())->toBe(0);
});

it('does not carry delegation into a later evaluation setup for the same faculty', function () {
    extract(facultySupervisorFixture());
    app(FacultySupervisorEvaluators::class)->save($owner, $offering, [$extra->id]);
    $cycles = app(EvaluationCycles::class);
    $first = $cycles->save($owner, $campus, $data);
    $cycles->publish($owner, $first);
    $secondClass = $offering->fresh()->replicate();
    $secondClass->evaluation_cycle_id = null;
    $secondClass->section = 'Next cycle';
    $secondClass->save();
    $secondClass->students()->attach($student);
    $secondClass->supervisorEvaluators()->sync([$head->id]);
    $second = $cycles->save($owner, $campus, [...$data, 'name' => 'Next evaluation', 'assignment_ids' => [$secondClass->id]]);
    $cycles->publish($owner, $second);
    expect(app(FacultySupervisorEvaluators::class)->taskIds($extra, $campus)->pluck('task.id')->all())
        ->toBe([$first->assignments()->where('evaluation_type', 'SEF')->value('id')]);
});

it('publishes scheduled tasks with only a selected supervisor and no department head', function () {
    extract(facultySupervisorFixture());
    $department->update(['supervisor_id' => null]);
    app(FacultySupervisorEvaluators::class)->save($owner, $offering, [$extra->id]);
    $cycles = app(EvaluationCycles::class);
    $cycle = $cycles->save($owner, $campus, [...$data, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(3)]);
    expect($cycles->readiness($cycle))->toMatchArray(['errors' => [], 'heads' => 1]);
    $cycles->publish($owner, $cycle);
    $task = $cycle->assignments()->where('evaluation_type', 'SEF')->sole();
    expect($task->isEvaluationAvailable())->toBeFalse()->and(app(FacultySupervisorEvaluators::class)->taskIds($extra, $campus)->count())->toBe(1);
});

it('keeps supervisor choices in the evaluator draft and saves them with the section roster', function () {
    extract(facultySupervisorFixture());
    $this->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($campus, isQuiet: true);
    $section = app(AcademicSections::class)->save($owner, $campus, ['name' => 'BSIT 3A', 'department_id' => $department->id, 'semester_id' => $offering->semester_id, 'is_active' => true]);
    $student->update(['student_department_id' => $department->id]);
    StudentAcademicRecord::create(['user_id' => $student->id, 'semester_id' => $offering->semester_id, 'program' => 'BSIT']);
    app(AcademicSections::class)->add($owner, $campus, $section, [$student->id]);
    $key = $department->id.'~'.$offering->semester_id.'~BSIT%203A';
    $params = ['ownerRecord' => $offering, 'pageClass' => EditTeachingClass::class];
    Livewire::test(StudentsRelationManager::class, $params)->mountTableAction('assignSections')
        ->setTableActionData(['sections' => [$key], 'supervisor_ids' => [$extra->id]])->call('unmountTableAction');
    expect($offering->supervisorEvaluators()->count())->toBe(0);
    Livewire::test(StudentsRelationManager::class, $params)->call('resumeWorkspaceDraft')
        ->assertTableActionDataSet(['supervisor_ids' => [$extra->id]])->callMountedTableAction()->assertHasNoTableActionErrors();
    expect($offering->supervisorEvaluators()->pluck('users.id')->all())->toBe([$extra->id]);
    $this->get(route('evaluation.report', $offering))->assertOk()->assertSee('Assigned supervisor evaluators for this faculty:')->assertSee($extra->name);
});

it('does not treat the old department-wide extra supervisor setting as faculty delegation', function () {
    extract(facultySupervisorFixture());
    $department->additionalSupervisors()->attach($extra);
    expect($department->hasEvaluationHead($extra))->toBeFalse()
        ->and(app(AssignedDataAccess::class)->departmentIds($extra, $campus))->toBe([$host->id]);
});

it('rejects an old evaluator draft after a concurrent supervisor change in the same timestamp', function () {
    extract(facultySupervisorFixture());
    $this->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($campus, isQuiet: true);
    $params = ['ownerRecord' => $offering, 'pageClass' => EditTeachingClass::class];
    Livewire::test(StudentsRelationManager::class, $params)->mountTableAction('assignSections')
        ->setTableActionData(['sections' => [], 'supervisor_ids' => [$extra->id]])->call('unmountTableAction');
    // Pivot changes need their own revision even if the parent timestamp is unchanged.
    $offering->supervisorEvaluators()->attach($head);
    Livewire::test(StudentsRelationManager::class, $params)->call('resumeWorkspaceDraft')->assertHasErrors(['workspaceDraft']);
    expect($offering->supervisorEvaluators()->pluck('users.id')->all())->toBe([$head->id]);
});

it('makes the department chairperson the evaluator of faculty teaching there without selecting them per class', function () {
    extract(facultySupervisorFixture());
    $chair = User::factory()->create(['is_student' => false]);
    $chair->assignRole('supervisor');
    $chair->campuses()->attach($campus);
    $a->update(['is_active' => true]);
    $cycles = app(EvaluationCycles::class);
    $cycle = $cycles->save($owner, $campus, $data);
    // Without a chairperson or a selected supervisor the evaluation cannot open.
    expect(implode(' ', $cycles->readiness($cycle)['errors']))->toContain('Select a supervisor evaluator');
    $department->update(['chairperson_id' => $chair->id]);
    $cycle = $cycle->fresh();
    expect($cycles->readiness($cycle)['errors'])->toBe([])->and($cycles->readiness($cycle)['heads'])->toBe(1);
    $cycles->publish($owner, $cycle);
    $task = $cycle->assignments()->where('evaluation_type', 'SEF')->sole();
    expect(app(FacultySupervisorEvaluators::class)->forTask($task)->pluck('users.id')->all())->toBe([$chair->id])
        ->and($offering->supervisorEvaluators()->count())->toBe(0);
    $answers = $b->fields()->pluck('name')->mapWithKeys(fn ($name) => [$name => 5])->all();
    $this->actingAs($chair)->get(route('evaluate.course-offering', $task))->assertOk();
    $this->post(route('evaluate.store', $task), ['fields' => $answers])->assertRedirect();
    expect($task->formSubmissions()->count())->toBe(1);
});

it('still needs another supervisor when the chairperson teaches the class', function () {
    extract(facultySupervisorFixture());
    $teacher = $offering->evaluatee;
    $teacher->assignRole('supervisor');
    $teacher->campuses()->syncWithoutDetaching([$campus->id]);
    $department->update(['chairperson_id' => $teacher->id]);
    $cycles = app(EvaluationCycles::class);
    $cycle = $cycles->save($owner, $campus, $data);
    expect(implode(' ', $cycles->readiness($cycle)['errors']))->toContain('cannot evaluate themselves');
    expect(fn () => $cycles->publish($owner, $cycle))->toThrow(ValidationException::class);
    app(FacultySupervisorEvaluators::class)->save($owner, $offering, [$extra->id]);
    $cycles->publish($owner, $cycle->fresh());
    $task = $cycle->assignments()->where('evaluation_type', 'SEF')->sole();
    expect(app(FacultySupervisorEvaluators::class)->forTask($task)->pluck('users.id')->all())->toBe([$extra->id]);
});

it('lets the home department chairperson rate their faculty who teach elsewhere and follows later changes', function () {
    extract(facultySupervisorFixture());
    $staff = function () use ($campus) {
        $user = User::factory()->create(['is_student' => false]);
        $user->assignRole('supervisor');
        $user->campuses()->attach($campus);

        return $user;
    };
    $faculty = $offering->evaluatee;
    $faculty->update(['is_instructor' => true, 'instructor_department_id' => $host->id]);
    [$homeChair, $teachingChair, $nextChair] = [$staff(), $staff(), $staff()];
    $host->update(['chairperson_id' => $homeChair->id]);
    $department->update(['chairperson_id' => $teachingChair->id]);
    $cycles = app(EvaluationCycles::class);
    $cycles->publish($owner, $cycles->save($owner, $campus, $data));
    $service = app(SupervisorEvaluationAssignments::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($campus, isQuiet: true);

    // Opening My Supervisor Tasks prepares the home-department task once.
    $this->actingAs($homeChair);
    expect(SupervisorEvaluations::canAccess())->toBeTrue();
    Livewire::test(SupervisorEvaluations::class)->assertOk();
    Livewire::test(SupervisorEvaluations::class)->assertOk();
    $homeTask = CourseOffering::where('evaluation_type', 'SEF')->where('teaching_department_id', $host->id)->sole();
    $teachingTask = CourseOffering::where('evaluation_type', 'SEF')->where('teaching_department_id', $department->id)->sole();
    expect($homeTask->user_id)->toBe($faculty->id)
        ->and($service->forViewer($homeChair, $campus)->pluck('id')->all())->toBe([$homeTask->id])
        ->and($service->forViewer($teachingChair, $campus)->pluck('id')->all())->toBe([$teachingTask->id])
        ->and(app(FacultySupervisorEvaluators::class)->forTask($homeTask)->pluck('users.id')->all())->toBe([$homeChair->id]);
    $answers = $homeTask->evaluationForm->fields()->pluck('name')->mapWithKeys(fn ($name) => [$name => 4])->all();
    $this->get(route('evaluate.course-offering', $homeTask))->assertOk();
    $this->post(route('evaluate.store', $homeTask), ['fields' => $answers])->assertRedirect();
    expect($homeTask->formSubmissions()->count())->toBe(1);

    // A new chairperson takes over at once and the previous one loses the task.
    $host->update(['chairperson_id' => $nextChair->id]);
    expect($service->forViewer($homeChair, $campus)->count())->toBe(0)
        ->and($service->forViewer($nextChair, $campus)->pluck('id')->all())->toBe([$homeTask->id]);
    $this->get(route('evaluate.course-offering', $homeTask))->assertForbidden();

    // Moving the faculty to another home department ends that chairperson's authority; the teaching chairperson keeps theirs.
    $faculty->update(['instructor_department_id' => null]);
    expect($service->forViewer($nextChair, $campus)->count())->toBe(0)
        ->and($service->forViewer($teachingChair, $campus)->pluck('id')->all())->toBe([$teachingTask->id]);
});

it('denies unselected deans and administrators while the department chairperson rates automatically', function () {
    extract(facultySupervisorFixture());
    $chair = User::factory()->create(['is_student' => false]);
    $chair->assignRole('supervisor');
    $chair->campuses()->attach($campus);
    $department->update(['chairperson_id' => $chair->id]);
    app(FacultySupervisorEvaluators::class)->save($owner, $offering, [$extra->id]);
    $cycles = app(EvaluationCycles::class);
    $cycle = $cycles->save($owner, $campus, $data);
    $cycles->publish($owner, $cycle);
    $task = $cycle->assignments()->where('evaluation_type', 'SEF')->sole();
    $answers = $b->fields()->pluck('name')->mapWithKeys(fn ($name) => [$name => 5])->all();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($campus, isQuiet: true);
    foreach ([$head, $owner] as $unselected) {
        $this->actingAs($unselected);
        expect(SupervisorEvaluations::canAccess())->toBeFalse()
            ->and(app(SupervisorEvaluationAssignments::class)->forViewer($unselected, $campus)->count())->toBe(0)
            ->and(app(WorkspaceSummary::class)->for($unselected, $campus)['cards'][0]['value'])->toBe(0);
        $this->get(route('evaluate.course-offering', $task))->assertForbidden();
        $this->post(route('evaluate.store', $task), ['fields' => $answers])->assertForbidden();
    }
    expect($task->formSubmissions()->count())->toBe(0);
    $this->actingAs($chair);
    expect(SupervisorEvaluations::canAccess())->toBeTrue()
        ->and(app(SupervisorEvaluationAssignments::class)->forViewer($chair, $campus)->pluck('id')->all())->toBe([$task->id]);
    $this->get(route('evaluate.course-offering', $task))->assertOk();
    // A dean gains evaluator authority only through the same explicit selection as other staff.
    app(FacultySupervisorEvaluators::class)->save($owner, $offering, [$head->id, $extra->id]);
    $this->actingAs($head)->get(route('evaluate.course-offering', $task))->assertOk();
    $this->post(route('evaluate.store', $task), ['fields' => $answers])->assertRedirect();
    app(FacultySupervisorEvaluators::class)->save($owner, $offering, [$extra->id]);
    $this->get(route('evaluate.course-offering', $task))->assertForbidden();
    expect($department->fresh()->supervisor_id)->toBe($head->id)
        ->and($task->formSubmissions()->count())->toBe(1);
});

it('does not let a dean or chairperson role answer a legacy supervisor evaluation without explicit selection', function () {
    extract(facultySupervisorFixture());
    $department->update(['chairperson_id' => $extra->id]);
    $legacy = app(EvaluationTemplates::class)->create('A', $campus);
    $legacy->update(['is_active' => true, 'evaluator_roles' => ['supervisor'], 'settings' => [...$legacy->settings, 'annex' => null, 'instrument' => 'SEF']]);
    $offering->update(['form_id' => $legacy->id, 'evaluation_type' => 'SEF', 'is_active' => true]);
    foreach ([$head, $extra, $owner] as $user) {
        expect(fn () => app(EvaluationAccessService::class)->authorize($offering->fresh(), $user))
            ->toThrow(AuthorizationException::class, 'supervisors explicitly selected');
    }
    expect(app(EvaluationTemplates::class)->create('B', $campus)->evaluator_roles)->toBe([]);
});

it('lists pending supervisor tasks before completed tasks after a submission', function () {
    extract(facultySupervisorFixture());
    app(FacultySupervisorEvaluators::class)->save($owner, $offering, [$head->id]);
    $cycles = app(EvaluationCycles::class);
    $cycle = $cycles->save($owner, $campus, $data);
    $cycles->publish($owner, $cycle);
    $done = $cycle->assignments()->where('evaluation_type', 'SEF')->sole();
    $other = $offering->fresh()->replicate();
    $other->user_id = User::factory()->create(['is_student' => false, 'is_instructor' => true, 'instructor_department_id' => $department->id])->id;
    $other->save();
    $other->supervisorEvaluators()->sync([$head->id]);
    app(SupervisorEvaluationAssignments::class)->prepareSource($other);
    $pending = $cycle->assignments()->where('evaluation_type', 'SEF')->where('user_id', $other->user_id)->sole();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($campus, isQuiet: true);
    $this->actingAs($head);
    $answers = $b->fields()->pluck('name')->mapWithKeys(fn ($name) => [$name => 4])->all();
    $this->post(route('evaluate.store', $done), ['fields' => $answers])->assertRedirect(route('evaluate.success', $done));
    Livewire::test(SupervisorEvaluations::class)->assertCanSeeTableRecords([$pending, $done], inOrder: true);
});
