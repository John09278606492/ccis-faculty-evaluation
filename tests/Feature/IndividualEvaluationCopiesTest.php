<?php

use App\Filament\Pages\IndividualEvaluationCopies;
use App\Models\Campus;
use App\Models\FormSubmission;
use App\Models\User;
use App\Services\EvaluationCopies;
use App\Services\EvaluationTemplates;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    ['offering' => $this->assignment] = evaluationFixture();
    $this->campus = $this->assignment->campus;
    $this->form = app(EvaluationTemplates::class)->create('A', $this->campus);
    $this->assignment->update(['form_id' => $this->form->id]);
    $this->staff = User::factory()->create(['is_student' => false, 'instructor_department_id' => $this->assignment->course->department_id]);
    $this->staff->campuses()->attach($this->campus);
    $this->staff->givePermissionTo(Permission::findOrCreate('page_EvaluationResults', 'web'));
    $answers = $this->form->fields()->pluck('name')->mapWithKeys(fn ($name) => [$name => 4])->all();
    $this->first = FormSubmission::create(['course_offering_id' => $this->assignment->id, 'form_id' => $this->form->id, 'data' => $answers, 'evaluator_identity' => ['name' => 'Private Student Name', 'student_id' => 'PRIVATE-001']]);
    $this->second = FormSubmission::create(['course_offering_id' => $this->assignment->id, 'form_id' => $this->form->id, 'data' => $answers]);
    $this->actingAs($this->staff);
});

it('prints only the selected responses and hides identity by default', function () {
    $service = app(EvaluationCopies::class);
    $url = $service->previewUrl($this->staff, $this->campus, [$this->first->id]);
    $response = $this->get($url)->assertOk()->assertSee('Student 1')->assertDontSee('Student 2')->assertDontSee('Private Student Name')->assertDontSee('PRIVATE-001')->assertSee('80.00%');
    expect($response->viewData('copies'))->toHaveCount(1);
    $this->get($url.'&identity=1')->assertForbidden();
    $bulk = $service->previewUrl($this->staff, $this->campus, [$this->first->id, $this->second->id]);
    $this->get($bulk)->assertOk()->assertSee('Student 1')->assertSee('Student 2');
    expect($this->first->getRawOriginal('evaluator_identity'))->not->toContain('Private Student Name');
    expect($this->first->toArray())->not->toHaveKey('evaluator_identity');
});

it('rejects student-role accounts with an inconsistent staff profile even when they retain report permissions', function () {
    $service = app(EvaluationCopies::class);
    $url = $service->previewUrl($this->staff, $this->campus, [$this->first->id]);
    $this->staff->assignRole('student');
    expect($this->staff->is_student)->toBeFalse()->and($this->staff->isStudent())->toBeTrue();
    expect(fn () => $service->previewUrl($this->staff, $this->campus, [$this->first->id]))->toThrow(HttpException::class);
    $this->get($url)->assertForbidden();
});

it('pairs distinct student responses without duplicating an odd selection and preserves the Annex B layout', function () {
    $third = $this->second->replicate();
    $third->data = [...$third->data, '_overall_comment' => 'Third student comment preserved.'];
    $third->save();
    $form = app(EvaluationTemplates::class)->create('B', $this->campus);
    $assignment = $this->assignment->replicate();
    $assignment->form_id = $form->id;
    $assignment->section = 'SEF PRINT';
    $assignment->save();
    $supervisor = FormSubmission::create(['course_offering_id' => $assignment->id, 'form_id' => $form->id,
        'data' => [...$form->fields()->pluck('name')->mapWithKeys(fn ($name) => [$name => 5])->all(), '_overall_comment' => 'Supervisor comment preserved.']]);
    $url = app(EvaluationCopies::class)->previewUrl($this->staff, $this->campus, [$this->first->id, $this->second->id, $third->id, $supervisor->id]);
    $response = $this->get($url)->assertOk()->assertDontSee('Private Student Name');
    $sheets = $response->viewData('sheets');
    expect(array_column($sheets, 'annex'))->toBe(['A', 'A', 'B'])
        ->and(array_map(fn ($sheet) => count($sheet['copies']), $sheets))->toBe([2, 1, 1]);
    expect(substr_count($response->getContent(), 'data-response-copy='))->toBe(4)
        ->and(substr_count($response->getContent(), 'class="unused-half"'))->toBe(1);
    if (getenv('RENDER_EVALUATION_PRINT_QA')) {
        file_put_contents(storage_path('app/qa/evaluation-copies-two-up.html'), $response->getContent());
        file_put_contents(storage_path('app/qa/evaluation-copies-long-comments.html'), str_replace('Supervisor comment preserved.', str_repeat('Extended supervisor feedback remains part of the submitted evaluation. ', 160).'END OF COMPLETE SUPERVISOR FEEDBACK', $response->getContent()));
    }
});

it('prints the submitted instrument snapshot instead of rewriting historical criteria', function () {
    $field = $this->form->fields()->whereIn('type', ['rating', 'likert_scale'])->first();
    $original = $field->question_text;
    DB::table('form_fields')->where('id', $field->id)->update(['label' => 'Changed after the submitted copy']);
    $url = app(EvaluationCopies::class)->previewUrl($this->staff, $this->campus, [$this->first->id]);
    $this->get($url)->assertOk()->assertSee($original)->assertDontSee('Changed after the submitted copy');
});

it('reveals identity only with permission and prevents another user reusing a selection', function () {
    $url = app(EvaluationCopies::class)->previewUrl($this->staff, $this->campus, [$this->first->id, $this->second->id]);
    $this->staff->givePermissionTo(Permission::findOrCreate('view_evaluator_identity_form', 'web'));
    $this->get($url.'&identity=1')->assertOk()->assertSee('Private Student Name')->assertSee('PRIVATE-001')->assertSee('Identity unavailable');
    $other = User::factory()->create(['is_student' => false]);
    $other->campuses()->attach($this->campus);
    $other->givePermissionTo('page_EvaluationResults');
    $this->actingAs($other)->get($url)->assertForbidden();
});

it('includes Annex B copies and supports the selected-copy modal', function () {
    $form = app(EvaluationTemplates::class)->create('B', $this->campus);
    $assignment = $this->assignment->replicate();
    $assignment->form_id = $form->id;
    $assignment->section = 'SEF';
    $assignment->save();
    $submission = FormSubmission::create(['course_offering_id' => $assignment->id, 'form_id' => $form->id, 'data' => $form->fields()->pluck('name')->mapWithKeys(fn ($name) => [$name => 5])->all()]);
    $url = app(EvaluationCopies::class)->previewUrl($this->staff, $this->campus, [$submission->id]);
    $this->get($url)->assertOk()->assertSee('SUPERVISOR’S EVALUATION')->assertSee('100.00%')->assertSee('Supervisor');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    Livewire::test(IndividualEvaluationCopies::class, ['faculty' => $this->assignment->user_id])->mountTableBulkAction('printSelected', [$this->first, $this->second])->assertSee('<iframe', false);
    Livewire::test(IndividualEvaluationCopies::class, ['faculty' => $this->assignment->user_id])->mountTableAction('printAll')->assertSee('<iframe', false);
});

it('resolves an existing keyed anonymous response only when identity reveal is authorized', function () {
    $student = User::factory()->create(['is_student' => true, 'student_id' => 'LEGACY-STUDENT']);
    $student->campuses()->attach($this->campus);
    $this->second->update(['submission_fingerprint' => hash_hmac('sha256', "evaluation:{$this->assignment->id}:user:{$student->id}", config('evaluations.fingerprint_key'))]);
    $url = app(EvaluationCopies::class)->previewUrl($this->staff, $this->campus, [$this->second->id]);
    $this->get($url)->assertOk()->assertDontSee('LEGACY-STUDENT');
    $this->staff->givePermissionTo(Permission::findOrCreate('view_evaluator_identity_form', 'web'));
    $this->get($url.'&identity=1')->assertOk()->assertSee('LEGACY-STUDENT')->assertSee($student->name);
    $otherCampus = Campus::create(['name' => 'Other campus', 'slug' => 'copy-other', 'is_active' => true]);
    $this->staff->campuses()->attach($otherCampus);
    expect(fn () => app(EvaluationCopies::class)->previewUrl($this->staff, $otherCampus, [$this->first->id]))->toThrow(HttpException::class);
    expect(app(EvaluationCopies::class)->faculties($otherCampus, $this->staff)->count())->toBe(0);
});

it('keeps the copies page available when an unrelated offering has an archived instructor', function () {
    $archived = User::factory()->create(['is_instructor' => true, 'is_student' => false]);
    $archived->campuses()->attach($this->campus);
    $old = $this->assignment->replicate();
    $old->user_id = $archived->id;
    $old->save();
    $archived->delete();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);

    Livewire::test(IndividualEvaluationCopies::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$this->assignment->evaluatee])
        ->assertCountTableRecords(1);
});

it('preserves the faculty name on historical copies after the instructor account is archived', function () {
    $faculty = $this->assignment->evaluatee;
    $name = $faculty->name;
    $faculty->delete();
    $url = app(EvaluationCopies::class)->previewUrl($this->staff, $this->campus, [$this->first->id]);

    $this->get($url)->assertOk()->assertSee($name);
});

it('groups student and supervisor responses into one instructor row', function () {
    $supervisorForm = app(EvaluationTemplates::class)->create('B', $this->campus);
    $class = $this->assignment->replicate();
    $class->section = 'SEF';
    $class->form_id = $supervisorForm->id;
    $class->save();
    FormSubmission::create(['course_offering_id' => $class->id, 'form_id' => $supervisorForm->id, 'data' => []]);
    $row = app(EvaluationCopies::class)->faculties($this->campus, $this->staff)->sole();
    expect($row->id)->toBe($this->assignment->user_id)
        ->and((int) $row->student_count)->toBe(2)
        ->and((int) $row->supervisor_count)->toBe(1)
        ->and((int) $row->response_count)->toBe(3);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    Livewire::test(IndividualEvaluationCopies::class)->assertCountTableRecords(1)->assertSee('View Copies');
    Livewire::test(IndividualEvaluationCopies::class, ['faculty' => $row->id])
        ->assertCountTableRecords(3)->assertSee('All Instructors');
});

it('counts only authorized teaching departments and blocks unrelated instructor detail links', function () {
    $department = \App\Models\Department::create(['name' => 'Other department', 'code' => 'OTHER', 'campus_id' => $this->campus->id, 'is_active' => true]);
    $course = $this->assignment->course->replicate();
    $course->department_id = $department->id;
    $course->code = 'OTHER-101';
    $course->save();
    $faculty = User::factory()->create(['is_instructor' => true, 'is_student' => false]);
    $faculty->campuses()->attach($this->campus);
    foreach ([$this->assignment->user_id, $faculty->id] as $facultyId) {
        $class = $this->assignment->replicate();
        $class->course_id = $course->id;
        $class->teaching_department_id = $department->id;
        $class->user_id = $facultyId;
        $class->save();
        FormSubmission::create(['course_offering_id' => $class->id, 'form_id' => $this->form->id, 'data' => []]);
    }
    $rows = app(EvaluationCopies::class)->faculties($this->campus, $this->staff)->get();
    expect($rows)->toHaveCount(1)->and((int) $rows->first()->response_count)->toBe(2);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    Livewire::test(IndividualEvaluationCopies::class, ['faculty' => $faculty->id])->assertNotFound();
});

it('recalculates grouped counts when the academic period filter changes', function () {
    $period = $this->assignment->semester->replicate();
    $period->name = 'Another term';
    $period->save();
    $class = $this->assignment->replicate();
    $class->semester_id = $period->id;
    $class->save();
    FormSubmission::create(['course_offering_id' => $class->id, 'form_id' => $this->form->id, 'data' => []]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    $page = Livewire::test(IndividualEvaluationCopies::class)->filterTable('semester_id', (string) $period->id)->assertCountTableRecords(1);
    expect((int) $page->instance()->getTableRecords()->first()->response_count)->toBe(1);
    $page->filterTable('semester_id', (string) $this->assignment->semester_id);
    expect((int) $page->instance()->getTableRecords()->first()->response_count)->toBe(2);
    $page->assertTableActionHasUrl('viewCopies', IndividualEvaluationCopies::getUrl(['faculty' => $this->assignment->user_id, 'period' => $this->assignment->semester_id]), $this->assignment->evaluatee);
});
