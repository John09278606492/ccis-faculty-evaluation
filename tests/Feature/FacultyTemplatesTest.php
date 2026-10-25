<?php

use App\Models\FormSubmission;
use App\Models\User;
use App\Services\EvaluationTemplates;
use Spatie\Permission\Models\Permission;

it('numbers imported criteria once in evaluation, preview and print without changing stored question identifiers', function () {
    ['offering' => $offering, 'form' => $form, 'student' => $student] = evaluationFixture();
    $field = $form->fields()->first();
    $field->update(['label' => '1. Comes to class on time.']);
    $student->givePermissionTo(Permission::findOrCreate('view_form', 'web'));
    $this->actingAs($student);
    $response = $this->get(route('evaluate.course-offering', $offering))->assertOk();
    $text = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($response->getContent())));
    expect($text)->toContain('1. Comes to class on time.')->not->toContain('1. 1. Comes');
    $this->get(route('form.preview', $form))->assertForbidden();
    $staff = User::factory()->create(['is_student' => false, 'instructor_department_id' => $offering->course->department_id]);
    $staff->campuses()->attach($offering->campus);
    $staff->givePermissionTo(Permission::findOrCreate('view_form', 'web'));
    $this->actingAs($staff);
    foreach ([route('form.preview', $form), route('form.preview', ['form' => $form, 'print' => 1])] as $url) {
        $response = $this->get($url)->assertOk();
        $text = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($response->getContent())));
        expect($text)->toContain('1. Comes to class on time.')->not->toContain('1. 1. Comes');
    }
    expect($field->fresh()->label)->toBe('1. Comes to class on time.')->and($field->fresh()->name)->toBe('teaching_quality');
});

it('creates editable source instruments with fifteen criteria and copies no responses', function () {
    ['offering' => $offering] = evaluationFixture();
    $templates = app(EvaluationTemplates::class);
    foreach (['A', 'B'] as $annex) {
        $form = $templates->create($annex, $offering->campus);
        expect($form->fields()->count())->toBe(15)
            ->and($form->fields()->where('type', 'likert_scale')->count())->toBe(15)
            ->and($form->rating_scale[0]['label'])->toBe('Always manifested')
            ->and($form->is_active)->toBeFalse();
        FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $offering->id, 'data' => []]);
        $copy = $templates->duplicate($form, $offering->campus, 'Edited copy');
        expect($copy->fields()->count())->toBe(15)->and($copy->submissions()->count())->toBe(0)->and($copy->slug)->not->toBe($form->slug);
        $copy->fields()->first()->update(['label' => 'Edited criterion']);
        expect($form->fields()->first()->label)->not->toBe('Edited criterion');
    }
});

it('previews a confidential summary with exact annex percentages and saves the development plan', function () {
    ['offering' => $offering] = evaluationFixture();
    $form = app(EvaluationTemplates::class)->create('A', $offering->campus);
    $form->update(['is_active' => true]);
    $offering->update(['form_id' => $form->id]);
    $data = $form->fields()->pluck('name')->mapWithKeys(fn ($name) => [$name => 4])->all();
    FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $offering->id, 'data' => $data]);
    $staff = User::factory()->create(['is_student' => false, 'instructor_department_id' => $offering->course->department_id]);
    $staff->campuses()->attach($offering->campus);
    foreach (['page_EvaluationResults', 'update_course::offering'] as $name) {
        $staff->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }
    $this->actingAs($staff)->get(route('evaluation.report', $offering))->assertOk()->assertSee('80.00')->assertSee('Pending supervisor evaluation')->assertSee('Print / Save PDF')->assertDontSee('NaN');
    $supervisorForm = app(EvaluationTemplates::class)->create('B', $offering->campus);
    $supervisorAssignment = $offering->replicate();
    $supervisorAssignment->form_id = $supervisorForm->id;
    $supervisorAssignment->section = 'Supervisor';
    $supervisorAssignment->save();
    FormSubmission::create(['form_id' => $supervisorForm->id, 'course_offering_id' => $supervisorAssignment->id, 'data' => $supervisorForm->fields()->pluck('name')->mapWithKeys(fn ($name) => [$name => 3])->all()]);
    $this->get(route('evaluation.report', $offering))->assertSee('80.00')->assertSee('60.00')->assertDontSee('No results available');
    $this->post(route('evaluation.report.save', $offering), ['annex' => 'D', 'areas' => 'Classroom feedback', 'activities' => 'Mentoring', 'action_plan' => 'Monthly review'])->assertRedirect(route('evaluation.report', ['offering' => $offering, 'annex' => 'D']));
    $this->get(route('evaluation.report', ['offering' => $offering, 'annex' => 'D']))->assertSee('Monthly review');
    $this->assertDatabaseHas('faculty_development_reports', ['user_id' => $offering->user_id, 'semester_id' => $offering->semester_id]);
});

it('renders both electronic and printable instruments behind campus permissions', function () {
    ['offering' => $offering] = evaluationFixture();
    $form = app(EvaluationTemplates::class)->create('B', $offering->campus);
    $staff = User::factory()->create(['is_student' => false, 'instructor_department_id' => $offering->course->department_id]);
    $staff->givePermissionTo(Permission::findOrCreate('view_form', 'web'));
    $this->actingAs($staff)->get(route('form.preview', ['form' => $form, 'print' => 1]))->assertForbidden();
    $staff->campuses()->attach($offering->campus);
    $this->get(route('form.preview', ['form' => $form, 'print' => 1]))->assertOk()->assertSee('Suggested Means for Verification')->assertSee('Total Score / 75')->assertSee('Print / Save PDF');
    $this->get(route('form.preview', $form))->assertOk()->assertSee('Preview Mode')->assertSee('Provides transparent');
});

it('denies report access across campuses and denies edits for read-only reviewers', function () {
    ['offering' => $offering, 'student' => $student] = evaluationFixture();
    $staff = User::factory()->create(['is_student' => false, 'instructor_department_id' => $offering->course->department_id]);
    $staff->givePermissionTo(Permission::findOrCreate('page_EvaluationResults', 'web'));
    $this->actingAs($staff)->get(route('evaluation.report', $offering))->assertForbidden();
    $staff->campuses()->attach($offering->campus);
    $this->get(route('evaluation.report', $offering))->assertOk();
    $this->post(route('evaluation.report.save', $offering), ['areas' => 'Unauthorized'])->assertForbidden();
    $this->actingAs($student)->get(route('evaluation.report', $offering))->assertForbidden();
});
