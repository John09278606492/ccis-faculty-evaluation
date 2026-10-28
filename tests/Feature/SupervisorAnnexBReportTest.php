<?php

use App\Models\CourseOffering;
use App\Models\FormSubmission;
use App\Models\User;
use App\Services\EvaluationTemplates;
use App\Services\SupervisorEvaluationAssignments;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('keeps SEF pending until the supervisor submits Annex B and then reports that rating', function () {
    ['offering' => $offering, 'student' => $student] = evaluationFixture();
    $form = app(EvaluationTemplates::class)->create('B', $offering->campus);
    $form->update(['is_active' => true]);
    $source = $offering;
    $source->evaluationForm->update(['is_active' => true]);
    $supervisor = User::factory()->create(['is_student' => false]);
    $supervisor->campuses()->attach($offering->campus);
    $supervisor->assignRole(Role::findOrCreate('supervisor', 'web'));
    $source->course->department->update(['supervisor_id' => $supervisor->id]);
    $source->supervisorEvaluators()->attach($supervisor);
    app(SupervisorEvaluationAssignments::class)->prepareSource($source);
    $offering = CourseOffering::where('evaluation_type', 'SEF')->sole();
    $supervisor->givePermissionTo(Permission::findOrCreate('page_EvaluationResults', 'web'));
    $this->actingAs($supervisor)->get(route('evaluation.report', $offering))->assertOk()->assertSee('Pending supervisor evaluation');
    $this->actingAs($student)->get(route('evaluate.course-offering', $offering))->assertForbidden();
    $answers = $form->fields()->pluck('name')->mapWithKeys(fn ($name) => [$name => 4])->all();
    $this->actingAs($supervisor)->post(route('evaluate.store', $offering), ['fields' => $answers, 'overall_comment' => 'Supervisor feedback'])->assertRedirect(route('evaluate.success', $offering));
    $response = $this->get(route('evaluation.report', $offering))->assertOk()->assertSee('Supervisor feedback')->assertDontSee('Pending supervisor evaluation');
    expect($response->viewData('ratings')['SEF'])->toBe(80.0)->and($response->viewData('ratings')['SET'])->toBeNull();
});

it('does not turn a manually labelled student form into an Annex B supervisor result', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    $offering->update(['evaluation_type' => 'SEF']);
    FormSubmission::create(['course_offering_id' => $offering->id, 'form_id' => $form->id, 'data' => ['teaching_quality' => 5, '_overall_comment' => 'Student feedback']]);
    $staff = User::factory()->create(['is_student' => false, 'instructor_department_id' => $offering->course->department_id]);
    $staff->campuses()->attach($offering->campus);
    $staff->givePermissionTo(Permission::findOrCreate('page_EvaluationResults', 'web'));
    $response = $this->actingAs($staff)->get(route('evaluation.report', $offering))->assertOk()->assertSee('Pending supervisor evaluation');
    expect($response->viewData('ratings')['SEF'])->toBeNull();
});
