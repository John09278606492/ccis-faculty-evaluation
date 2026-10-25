<?php

use App\Models\FormSubmission;
use App\Services\EvaluationTally;
use App\Services\EvaluationTemplates;

it('shows anonymous student ratings with a correct row total and no identity metadata', function () {
    ['offering' => $offering, 'form' => $form, 'student' => $student] = evaluationFixture();
    foreach (['q2', 'q3'] as $index => $name) {
        $form->fields()->create(['name' => $name, 'label' => $name, 'type' => 'likert_scale', 'order' => $index + 2]);
    }
    FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $offering->id, 'user_id' => $student->id, 'data' => ['teaching_quality' => 5, 'q2' => 3, 'q3' => 4]]);
    FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $offering->id, 'data' => ['teaching_quality' => 4]]);
    $tally = app(EvaluationTally::class)->build($offering, $offering->formSubmissions()->get());
    expect($tally['rows'][0])->toBe(['label' => 'Student 1', 'values' => [5.0, 3.0, 4.0], 'total' => 12.0, 'average' => 4.0, 'percentage' => 80.0]);
    expect($tally['rows'][1]['label'])->toBe('Student 2')->and($tally['rows'][1]['total'])->toBeNull();
    expect(json_encode($tally))->not->toContain($student->email)->not->toContain('user_id')->not->toContain('submission_fingerprint');
    $html = view('filament.pages.partials.evaluator-tally', compact('tally'))->render();
    expect($html)->toContain('Student 1')->toContain('80.00%')->toContain('Incomplete');
});

it('labels Annex B responses Supervisor and excludes responses to a previous form', function () {
    ['offering' => $offering, 'form' => $previous] = evaluationFixture();
    $form = app(EvaluationTemplates::class)->create('B', $offering->campus);
    $offering->update(['form_id' => $form->id]);
    FormSubmission::create(['form_id' => $previous->id, 'course_offering_id' => $offering->id, 'data' => ['teaching_quality' => 1]]);
    FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $offering->id, 'data' => $form->fields()->pluck('name')->mapWithKeys(fn ($name) => [$name => 4])->all()]);
    $tally = app(EvaluationTally::class)->build($offering, $offering->formSubmissions()->get());
    expect($tally['rows'])->toHaveCount(1)->and($tally['rows'][0]['label'])->toBe('Supervisor')->and($tally['rows'][0]['total'])->toBe(60.0);
});
