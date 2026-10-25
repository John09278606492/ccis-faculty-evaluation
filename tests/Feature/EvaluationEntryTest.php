<?php

use App\Models\FormSubmission;

it('gives different students separate entry limits on a shared campus network', function () {
    for ($i = 0; $i < 12; $i++) {
        $this->post(route('evaluation.identify'), ['student_id' => 'SHARED-NETWORK-'.$i])->assertRedirect();
    }
    for ($i = 0; $i < 10; $i++) {
        $this->post(route('evaluation.identify'), ['student_id' => 'REPEATED-UNKNOWN-ID'])->assertRedirect();
    }
    $this->post(route('evaluation.identify'), ['student_id' => 'REPEATED-UNKNOWN-ID'])->assertStatus(429);
    $this->post(route('evaluation.identify'), ['student_id' => 'ANOTHER-SHARED-ID'])->assertRedirect();
});

it('lets a student without a PIN enter assigned evaluations without assessment authentication', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $student->update(['student_id' => 'EVAL-ID-ONLY']);
    $this->post(route('evaluation.identify'), ['student_id' => 'EVAL-ID-ONLY'])->assertRedirect(route('evaluation.entry'));
    $this->assertGuest();
    $this->get(route('evaluation.entry'))->assertOk()->assertSee($offering->evaluatee->name);
    $this->get(route('evaluate.course-offering', $offering))->assertOk();
    $this->post(route('evaluate.store', $offering), ['fields' => ['teaching_quality' => 5]])->assertRedirect(route('evaluate.success', $offering));
    expect(FormSubmission::count())->toBe(1)->and(FormSubmission::first()->user_id)->toBeNull();
    $this->get(route('evaluation.entry'))->assertSee('Completed');
    $this->get('/'.$offering->campus->id.'/my-assessments')->assertRedirect('/login');
    $this->assertGuest();
});

it('routes direct evaluation links to the ID entry and returns to that evaluation', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $student->update(['student_id' => 'DIRECT-ID']);
    $this->get(route('evaluate.course-offering', $offering))->assertRedirect(route('evaluation.entry'));
    $this->post(route('evaluation.identify'), ['student_id' => 'DIRECT-ID'])->assertRedirect(route('evaluate.course-offering', $offering));
});

it('rechecks enrollment and expires evaluation-only access', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $student->update(['student_id' => 'EXPIRE-ID']);
    $this->post(route('evaluation.identify'), ['student_id' => 'EXPIRE-ID']);
    $offering->students()->detach($student);
    $this->get(route('evaluate.course-offering', $offering))->assertForbidden();
    $this->travel(31)->minutes();
    $this->get(route('evaluate.course-offering', $offering))->assertRedirect(route('evaluation.entry'));
});

it('rejects unknown IDs and allows switching evaluation identities without signing into the panel', function () {
    ['student' => $student] = evaluationFixture();
    $student->update(['student_id' => 'KNOWN-ID']);
    $this->post(route('evaluation.identify'), ['student_id' => 'UNKNOWN'])->assertSessionHasErrors('student_id');
    $this->post(route('evaluation.identify'), ['student_id' => 'KNOWN-ID']);
    $this->post(route('evaluation.leave'))->assertRedirect(route('evaluation.entry'));
    $this->get(route('evaluation.entry'))->assertSee('Continue to evaluations');
    $this->assertGuest();
});
