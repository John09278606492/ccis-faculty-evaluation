<?php

use App\Models\FormSubmission;
use App\Models\User;
use Spatie\Permission\Models\Permission;

it('inherits the form type unless the assignment explicitly chooses a different evaluator type', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    expect($offering->evaluation_type_code)->toBe('SET');
    $form->update(['evaluator_roles' => []]);
    expect($offering->fresh()->evaluation_type_code)->toBe('OTHER');
    $form->update(['settings' => ['instrument' => 'SET']]);
    expect($offering->fresh()->evaluation_type_label)->toBe('Student Evaluation (SET)');
    $offering->update(['evaluation_type' => 'PEER']);
    expect($offering->fresh()->evaluation_type_label)->toBe('Faculty / Peer Evaluation');
    $form->update(['settings' => ['instrument' => 'SEF']]);
    expect($offering->fresh()->evaluation_type_code)->toBe('PEER');
    $offering->update(['evaluation_type' => null]);
    expect($offering->fresh()->evaluation_type_label)->toBe('Supervisor Evaluation (SEF)');
});

it('separates report totals by assignment type even when assignments use the same form', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    $offering->update(['evaluation_type' => 'SET']);
    $peer = $offering->replicate();
    $peer->evaluation_type = 'PEER';
    $peer->section = 'Peer';
    $peer->save();
    FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $offering->id, 'data' => ['teaching_quality' => 4]]);
    FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $peer->id, 'data' => ['teaching_quality' => 2]]);
    $staff = User::factory()->create(['is_student' => false, 'instructor_department_id' => $offering->course->department_id]);
    $staff->campuses()->attach($offering->campus);
    $staff->givePermissionTo(Permission::findOrCreate('page_EvaluationResults', 'web'));
    $response = $this->actingAs($staff)->get(route('evaluation.report', ['offering' => $offering, 'annex' => 'D']));
    $response->assertOk()->assertSee('Results by Evaluation Type')->assertSee('Student Evaluation (SET)')->assertSee('Faculty / Peer Evaluation')->assertSee('80.00%')->assertSee('40.00%');
    expect($response->viewData('ratings')['SET'])->toBe(80.0)
        ->and($response->viewData('ratings')['SEF'])->toBeNull()
        ->and($response->viewData('rows')->pluck('instrument')->all())->toBe(['SET', 'PEER']);
});

it('shows the assignment type on the evaluator form', function () {
    ['offering' => $offering, 'student' => $student] = evaluationFixture();
    $offering->update(['evaluation_type' => 'SET']);
    $this->actingAs($student)->get(route('evaluate.course-offering', $offering))->assertOk()->assertSee('Student Evaluation (SET)');
});
