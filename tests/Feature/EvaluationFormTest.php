<?php

use App\Models\Campus;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The evaluation form a student fills in: when it is open, what it accepts and
 * what the instructor sees when previewing it.
 */
it('sends a guest to sign in and remembers where they were going', function () {
    ['offering' => $offering] = evaluationFixture();

    $this->get(route('evaluate.course-offering', $offering))
        ->assertRedirect(route('evaluation.entry'));

    expect(session('evaluation.intended_offering'))->toBe($offering->id);
});

it('shows the form to an enrolled student', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();

    $this->actingAs($student)
        ->get(route('evaluate.course-offering', $offering))
        ->assertOk()
        ->assertSee('Teaching quality');
});

it('tells the student the form is not open yet', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $offering->evaluationForm->update(['starts_at' => now()->addDay()]);

    $this->actingAs($student)
        ->get(route('evaluate.course-offering', $offering))
        ->assertOk()
        ->assertSee('Opens on:');
});

it('tells the student the form has closed', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $offering->evaluationForm->update(['ends_at' => now()->subDay()]);

    $this->actingAs($student)
        ->get(route('evaluate.course-offering', $offering))
        ->assertOk()
        ->assertSee('This evaluation has ended.');
});

it('tells the student the form is inactive', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $offering->evaluationForm->update(['is_active' => false]);

    $this->actingAs($student)
        ->get(route('evaluate.course-offering', $offering))
        ->assertOk()
        ->assertSee('The form has been deactivated.');
});

it('stops accepting submissions when the limit is reached', function () {
    ['student' => $student, 'offering' => $offering, 'form' => $form] = evaluationFixture();
    $form->update(['max_submissions' => 1]);
    FormSubmission::create([
        'form_id' => $form->id,
        'course_offering_id' => $offering->id,
        'submission_fingerprint' => 'fp-someone-else',
        'data' => ['teaching_quality' => 5],
    ]);

    $this->actingAs($student)
        ->get(route('evaluate.course-offering', $offering))
        ->assertOk()
        ->assertSee('The form has been deactivated.');
});

it('remembers that the student already submitted', function () {
    ['student' => $student, 'offering' => $offering, 'form' => $form] = evaluationFixture();

    $this->actingAs($student)
        ->post(route('evaluate.store', $offering), ['fields' => ['teaching_quality' => 5]])
        ->assertRedirect(route('evaluate.success', $offering));

    $this->actingAs($student)
        ->get(route('evaluate.course-offering', $offering))
        ->assertOk()
        ->assertSee('already submitted');
});

it('shows the thanks page after a submission', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();

    $this->actingAs($student)
        ->post(route('evaluate.store', $offering), ['fields' => ['teaching_quality' => 5]])
        ->assertRedirect(route('evaluate.success', $offering));

    $this->actingAs($student)
        ->get(route('evaluate.success', $offering))
        ->assertOk();
});

it('refuses the thanks page to a guest', function () {
    ['offering' => $offering] = evaluationFixture();

    $this->get(route('evaluate.success', $offering))->assertRedirect(route('evaluation.entry'));
});

it('sends a guest who tries to submit to sign in', function () {
    ['offering' => $offering] = evaluationFixture();

    $this->from(route('evaluate.course-offering', $offering))
        ->post(route('evaluate.store', $offering), ['fields' => ['teaching_quality' => 5]])
        ->assertRedirect(route('evaluation.entry'));
});

it('requires the rating when the field is required', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();

    $this->actingAs($student)
        ->post(route('evaluate.store', $offering), ['fields' => []])
        ->assertSessionHasErrors('fields.teaching_quality');

    expect(FormSubmission::count())->toBe(0);
});

it('rejects a rating that is not on the scale', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();

    $this->actingAs($student)
        ->post(route('evaluate.store', $offering), ['fields' => ['teaching_quality' => 9]])
        ->assertSessionHasErrors('fields.teaching_quality');
});

it('ignores legacy required criterion comments and keeps overall comments available', function () {
    ['student' => $student, 'offering' => $offering, 'form' => $form] = evaluationFixture();
    $form->update(['scoring_config' => ['field_comments' => 'required']]);

    $this->actingAs($student)
        ->get(route('evaluate.course-offering', $offering))
        ->assertOk()
        ->assertDontSee('name="field_comments[', false)
        ->assertSee('name="overall_comment"', false);

    $this->post(route('evaluate.store', $offering), ['fields' => ['teaching_quality' => 5]])
        ->assertRedirect(route('evaluate.success', $offering));
});

it('requires an overall comment when the form asks for one', function () {
    ['student' => $student, 'offering' => $offering, 'form' => $form] = evaluationFixture();
    $form->update(['published_at' => now()]);
    $form->update(['scoring_config' => ['overall_comment' => 'required']]);

    $this->actingAs($student)
        ->post(route('evaluate.store', $offering), ['fields' => ['teaching_quality' => 5]])
        ->assertSessionHasErrors('overall_comment');
});

it('saves only the overall comment with the answers', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();

    $this->actingAs($student)->post(route('evaluate.store', $offering), [
        'fields' => ['teaching_quality' => 5],
        'field_comments' => ['teaching_quality' => 'Clear explanations.'],
        'overall_comment' => 'Very effective instructor.',
    ])->assertRedirect(route('evaluate.success', $offering));

    $submission = FormSubmission::sole();
    expect($submission->data)->not->toHaveKey('_field_comments')
        ->and($submission->data['_overall_comment'])->toBe('Very effective instructor.');
});

it('leaves out a comment the student left blank', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();

    $this->actingAs($student)->post(route('evaluate.store', $offering), [
        'fields' => ['teaching_quality' => 5],
        'field_comments' => ['teaching_quality' => '   '],
    ])->assertRedirect(route('evaluate.success', $offering));

    expect(FormSubmission::sole()->data)->not->toHaveKey('_field_comments');
});

it('rejects a file upload question', function () {
    ['student' => $student, 'offering' => $offering, 'form' => $form] = evaluationFixture();
    FormField::create([
        'form_id' => $form->id,
        'name' => 'evidence',
        'label' => 'Evidence',
        'type' => 'file',
        'is_required' => false,
        'order' => 2,
    ]);

    $this->actingAs($student)
        ->post(route('evaluate.store', $offering), [
            'fields' => ['teaching_quality' => 5, 'evidence' => 'anything'],
        ])->assertSessionHasErrors('fields.evidence');
});

it('validates a choice field against its options', function () {
    ['student' => $student, 'offering' => $offering, 'form' => $form] = evaluationFixture();
    FormField::create([
        'form_id' => $form->id,
        'name' => 'shift',
        'label' => 'Shift',
        'type' => 'select',
        'options' => [['value' => 'day', 'label' => 'Day'], ['value' => 'night', 'label' => 'Night']],
        'is_required' => false,
        'order' => 2,
    ]);

    $this->actingAs($student)
        ->post(route('evaluate.store', $offering), ['fields' => ['shift' => 'evening']])
        ->assertSessionHasErrors('fields.shift');

    $this->actingAs($student)
        ->post(route('evaluate.store', $offering), ['fields' => ['teaching_quality' => 5, 'shift' => 'day']])
        ->assertRedirect(route('evaluate.success', $offering));
});

it('validates an email field', function () {
    ['student' => $student, 'offering' => $offering, 'form' => $form] = evaluationFixture();
    FormField::create([
        'form_id' => $form->id,
        'name' => 'email',
        'label' => 'Email',
        'type' => 'email',
        'is_required' => false,
        'order' => 2,
    ]);

    $this->actingAs($student)
        ->post(route('evaluate.store', $offering), ['fields' => ['email' => 'not-an-email']])
        ->assertSessionHasErrors('fields.email');
});

it('submits a form that has more than one field', function () {
    ['student' => $student, 'offering' => $offering, 'form' => $form] = evaluationFixture();
    FormField::create([
        'form_id' => $form->id,
        'name' => 'respect',
        'label' => 'Respect',
        'type' => 'likert_scale',
        'is_required' => false,
        'order' => 2,
    ]);

    $this->actingAs($student)
        ->post(route('evaluate.store', $offering), [
            'fields' => ['teaching_quality' => 5, 'respect' => 1],
        ])
        ->assertRedirect(route('evaluate.success', $offering));

    expect(FormSubmission::sole()->data)->toMatchArray([
        'teaching_quality' => 5,
        'respect' => 1,
    ]);
});

it('lets only a user who may view forms preview one', function () {
    ['student' => $student, 'offering' => $offering, 'form' => $form] = evaluationFixture();

    $this->actingAs($student)
        ->get(route('form.preview', $form))
        ->assertForbidden();

    $staff = User::factory()->create(['is_student' => false, 'instructor_department_id' => $offering->course->department_id]);
    $staff->givePermissionTo(Permission::findOrCreate('view_form', 'web'));
    $staff->campuses()->attach($offering->campus->id);

    $this->actingAs($staff)
        ->get(route('form.preview', $form))
        ->assertOk()
        ->assertSee('Teaching quality');
});

it('refuses a preview of a form from another campus', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    $staff = User::factory()->create();
    $staff->givePermissionTo(Permission::findOrCreate('view_form', 'web'));
    $other = Campus::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'is_active' => true]);
    $staff->campuses()->attach($other->id);

    $this->actingAs($staff)
        ->get(route('form.preview', $form))
        ->assertForbidden();
});

it('lets a super admin preview a form from any campus', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    // RolesSeeder gives super_admin every permission; a bare test role has none.
    $admin->givePermissionTo(Permission::findOrCreate('view_form', 'web'));

    $this->actingAs($admin)
        ->get(route('form.preview', $form))
        ->assertOk();
});

it('refuses a guest a form preview', function () {
    ['form' => $form] = evaluationFixture();

    $this->get(route('form.preview', $form))->assertRedirect();
});
