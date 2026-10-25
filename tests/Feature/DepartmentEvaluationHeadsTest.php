<?php

use App\Filament\Pages\SupervisorEvaluations;
use App\Models\Campus;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\User;
use App\Services\EvaluationTemplates;
use App\Services\SupervisorEvaluationAssignments;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

it('prepares one pending Annex B task explicitly and keeps page visits read only', function () {
    ['offering' => $source] = evaluationFixture();
    $head = User::factory()->create(['is_student' => false]);
    $head->assignRole(Role::findOrCreate('supervisor', 'web'));
    $head->campuses()->attach($source->campus);
    $source->course->department->update(['supervisor_id' => $head->id]);
    $second = $source->replicate();
    $second->section = 'B';
    $second->save();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($source->campus, isQuiet: true);
    $this->actingAs($head);
    $query = CourseOffering::where('user_id', $source->user_id)->whereHas('evaluationForm', fn ($q) => $q->where('settings->annex', 'B'));
    expect(SupervisorEvaluations::canAccess())->toBeFalse();
    $source->supervisorEvaluators()->attach($head);
    expect($query->count())->toBe(0);
    app(SupervisorEvaluationAssignments::class)->prepare($head, $source->campus);
    Livewire::test(SupervisorEvaluations::class)->assertSee('Complete Annex B')->assertSee('Pending');
    expect($query->count())->toBe(1);
    $assignment = $query->first();
    $this->get(route('evaluate.course-offering', $assignment))->assertOk();
    Livewire::test(SupervisorEvaluations::class);
    app(SupervisorEvaluationAssignments::class)->prepare($head, $source->campus);
    expect($query->count())->toBe(1);
});

it('allows explicitly selected heads of each teaching department to evaluate a visiting faculty only within their department', function () {
    ['offering' => $home, 'student' => $student] = evaluationFixture();
    $campus = $home->campus;
    $homeDepartment = $home->course->department;
    $hostDepartment = Department::create(['campus_id' => $campus->id, 'code' => 'CTAS', 'name' => 'CTAS', 'is_active' => true]);
    $staffRole = Role::findOrCreate('department_staff', 'web');
    $heads = [];
    foreach (range(1, 3) as $number) {
        $head = User::factory()->create(['is_student' => false]);
        $head->assignRole($staffRole);
        $head->campuses()->attach($campus);
        $heads[] = $head;
    }
    $homeDepartment->update(['supervisor_id' => $heads[0]->id]);
    $hostDepartment->update(['supervisor_id' => $heads[1]->id, 'chairperson_id' => $heads[2]->id]);
    $form = app(EvaluationTemplates::class)->create('B', $campus);
    $form->update(['is_active' => true]);
    $home->evaluationForm->update(['is_active' => true]);
    $course = Course::create(['department_id' => $hostDepartment->id, 'code' => 'CTAS101', 'name' => 'Visiting Faculty Course', 'is_active' => true]);
    $host = $home->replicate();
    $host->course_id = $course->id;
    $host->save();
    $home->supervisorEvaluators()->sync([$heads[0]->id]);
    $host->supervisorEvaluators()->sync([$heads[1]->id, $heads[2]->id]);
    $service = app(SupervisorEvaluationAssignments::class);
    $service->prepareSource($home);
    $service->prepareSource($host);
    $home = CourseOffering::where('evaluation_type', 'SEF')->where('teaching_department_id', $homeDepartment->id)->sole();
    $host = CourseOffering::where('evaluation_type', 'SEF')->where('teaching_department_id', $hostDepartment->id)->sole();
    $this->actingAs($heads[0])->get(route('evaluate.course-offering', $home))->assertOk();
    $this->get(route('evaluate.course-offering', $host))->assertForbidden();
    $this->actingAs($heads[1])->get(route('evaluate.course-offering', $host))->assertOk();
    $this->get(route('evaluate.course-offering', $home))->assertForbidden();
    $this->actingAs($heads[2])->get(route('evaluate.course-offering', $host))->assertOk();
    $answers = $form->fields()->pluck('name')->mapWithKeys(fn ($name) => [$name => 4])->all();
    $this->actingAs($heads[0])->post(route('evaluate.store', $home), ['fields' => $answers])->assertRedirect(route('evaluate.success', $home));
    $this->actingAs($heads[1])->post(route('evaluate.store', $host), ['fields' => $answers])->assertRedirect(route('evaluate.success', $host));
    $this->actingAs($student)->post(route('evaluate.store', $host), ['fields' => $answers])->assertForbidden();
    $this->assertDatabaseCount('form_submissions', 2);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($campus, isQuiet: true);
    $this->actingAs($heads[1]);
    Livewire::test(SupervisorEvaluations::class)->assertCanSeeTableRecords([$host])->assertCanNotSeeTableRecords([$home]);
    // Leadership changes do not revoke explicitly assigned evaluator authority.
    $hostDepartment->update(['supervisor_id' => null]);
    $this->get(route('evaluate.course-offering', $host))->assertOk();
    CourseOffering::where('course_id', $course->id)->first()->supervisorEvaluators()->detach($heads[1]);
    $this->get(route('evaluate.course-offering', $host))->assertForbidden();
});

it('rejects student and other-campus department heads and prevents self evaluation', function () {
    ['offering' => $offering, 'student' => $student] = evaluationFixture();
    $department = $offering->course->department;
    expect(fn () => $department->update(['supervisor_id' => $student->id]))->toThrow(ValidationException::class);
    $staff = User::factory()->create(['is_student' => false]);
    $staff->assignRole(Role::findOrCreate('instructor', 'web'));
    $other = Campus::create(['name' => 'Other', 'slug' => 'other', 'is_active' => true]);
    $staff->campuses()->attach($other);
    expect(fn () => $department->fresh()->update(['chairperson_id' => $staff->id]))->toThrow(ValidationException::class);
    $faculty = $offering->evaluatee;
    $faculty->assignRole('instructor');
    $faculty->campuses()->attach($offering->campus);
    $department->fresh()->update(['supervisor_id' => $faculty->id]);
    $form = app(EvaluationTemplates::class)->create('B', $offering->campus);
    $form->update(['is_active' => true]);
    $offering->update(['form_id' => $form->id]);
    $this->actingAs($faculty)->get(route('evaluate.course-offering', $offering))->assertForbidden();
});
