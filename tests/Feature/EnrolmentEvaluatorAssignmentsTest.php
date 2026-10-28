<?php

use App\Filament\Resources\CourseOfferingResource\RelationManagers\StudentsRelationManager;
use App\Filament\Resources\TeachingClassResource\Pages\EditTeachingClass;
use App\Models\AcademicSection;
use App\Models\FormSubmission;
use App\Models\StudentSubjectEnrolment;
use App\Models\User;
use App\Services\EnrolmentEvaluatorAssignments;
use App\Services\EvaluationSections;
use App\Services\EvaluationSubmissionIdentity;
use App\Services\EvaluationTally;
use App\Services\StudentAcademicRecords;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    ['offering' => $this->class, 'student' => $this->student, 'form' => $this->form] = evaluationFixture(false);
    $this->campus = $this->class->campus;
    $this->actor = User::factory()->create(['is_student' => false]);
    $this->actor->assignRole('super_admin');
    $this->actor->campuses()->attach($this->campus);
    foreach (['manage_evaluators_course::offering', 'update_course::offering', 'view_course::offering', 'view_any_course::offering',
        'view_academic_students', 'manage_academic_students', 'page_EvaluationResults'] as $name) {
        $this->actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }
    $this->student->update(['student_id' => 'ENROL-001', 'student_department_id' => $this->class->course->department_id]);
    app(StudentAcademicRecords::class)->save($this->student, $this->class->semester_id, ['program' => 'BSIT', 'subjects' => [
        ['subject_code' => $this->class->course->code, 'description' => $this->class->course->name, 'units' => 3],
    ]]);
    $this->subject = StudentSubjectEnrolment::sole()->subject_key;
    $this->form->update(['settings' => ['annex' => 'A']]);
    $this->class->update(['evaluation_type' => 'SET']);
    $this->actingAs($this->actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    $this->service = app(EnrolmentEvaluatorAssignments::class);
});

it('links an enrolled student without a section only to a reviewed unambiguous class', function () {
    $p = $this->service->preview($this->actor, $this->class, $this->subject);
    expect($p['automatic_ids'])->toBe([$this->student->id])->and($p['rows'][0]['section'])->toBeNull()
        ->and($this->class->students()->count())->toBe(0);
    expect($this->service->assign($this->actor, $this->class, $this->subject, [], $p['automatic_ids'], $p['hash']))->toBe(1);
    $this->actingAs($this->student)->get(route('evaluate.course-offering', $this->class))->assertOk();
    $this->post(route('evaluate.store', $this->class), ['fields' => ['teaching_quality' => 5], 'overall_comment' => 'Good teaching.'])->assertRedirect();
    $response = FormSubmission::sole();
    expect($response->data['teaching_quality'])->toBe(5)->and($response->data['_evaluation_context']['section'])->toBeNull()
        ->and($response->user_id)->toBeNull()->and(app(EvaluationSections::class)->label($response, $this->class))->toBe('Section pending');
});

it('does not guess a teacher when several instructors teach the enrolled subject', function () {
    $otherTeacher = User::factory()->create(['is_instructor' => true]);
    $otherTeacher->campuses()->attach($this->campus);
    $otherClass = $this->class->replicate();
    $otherClass->user_id = $otherTeacher->id;
    $otherClass->section = 'B';
    $otherClass->save();
    $p = $this->service->preview($this->actor, $this->class, $this->subject);
    expect($p['automatic_ids'])->toBe([])->and($p['rows'][0]['status'])->toBe('Confirm teacher / class');
    $this->service->assign($this->actor, $this->class, $this->subject, [], [$this->student->id], $p['hash']);
    expect($this->class->students()->count())->toBe(1)->and($otherClass->students()->count())->toBe(0);
});

it('prevents a second assignment across section classes of the same faculty and subject', function () {
    $p = $this->service->preview($this->actor, $this->class, $this->subject);
    $this->service->assign($this->actor, $this->class, $this->subject, [], [$this->student->id], $p['hash']);
    $other = $this->class->replicate();
    $other->section = 'B';
    $other->save();
    $p = $this->service->preview($this->actor, $other, $this->subject);
    expect($p['automatic_ids'])->toBe([])->and($p['eligible_ids'])->toBe([]);
    expect(fn () => $this->service->assign($this->actor, $other, $this->subject, [], [$this->student->id], $p['hash']))->toThrow(HttpException::class);
    expect($other->students()->count())->toBe(0);
});

it('rechecks enrolments and permissions before saving the reviewed matches', function () {
    $p = $this->service->preview($this->actor, $this->class, $this->subject);
    $this->actor->revokePermissionTo('manage_evaluators_course::offering');
    expect(fn () => $this->service->assign($this->actor, $this->class, $this->subject, [], [$this->student->id], $p['hash']))->toThrow(HttpException::class);
    $this->actor->givePermissionTo('manage_evaluators_course::offering');
    $student = $this->student->replicate();
    $student->email = 'another@example.test';
    $student->student_id = 'ENROL-002';
    $student->save();
    $student->assignRole('student');
    $student->campuses()->attach($this->campus);
    app(StudentAcademicRecords::class)->save($student, $this->class->semester_id, ['program' => 'BSIT', 'subjects' => [
        ['subject_code' => $this->class->course->code, 'description' => $this->class->course->name, 'units' => 3],
    ]]);
    expect(fn () => $this->service->assign($this->actor, $this->class, $this->subject, [], [$this->student->id], $p['hash']))->toThrow(ValidationException::class);
    expect($this->class->students()->count())->toBe(0);
});

it('assigns a section later and classifies pending anonymous responses without changing their scores', function () {
    $this->class->students()->attach($this->student, ['enrolled_at' => now()]);
    $fingerprint = app(EvaluationSubmissionIdentity::class)->forUser($this->class, $this->student->id);
    $response = FormSubmission::create(['course_offering_id' => $this->class->id, 'form_id' => $this->form->id,
        'submission_fingerprint' => $fingerprint, 'data' => ['teaching_quality' => 4, '_overall_comment' => 'Keep this comment.', '_evaluation_context' => ['section' => null]], 'submitted_at' => now()]);
    $submitted = $response->submitted_at;
    $section = AcademicSection::create(['name' => 'BSIT 3A', 'department_id' => $this->class->course->department_id, 'semester_id' => $this->class->semester_id, 'is_active' => true]);
    $result = app(EvaluationSections::class)->assign($this->actor, $this->class, [$this->student->id], $section->id);
    $response->refresh();
    expect($result)->toBe(['students' => 1, 'responses' => 1])->and($response->data['teaching_quality'])->toBe(4)
        ->and($response->data['_overall_comment'])->toBe('Keep this comment.')->and($response->submitted_at->equalTo($submitted))->toBeTrue()
        ->and($response->user_id)->toBeNull()->and($this->student->studentSectionMemberships()->where('academic_section_id', $section->id)->exists())->toBeTrue();
    $tally = app(EvaluationTally::class)->build($this->class->fresh(), collect([$response]));
    expect($tally['rows'][0]['section'])->toBe('BSIT 3A')->and($tally['rows'][0]['label'])->toBe('Student 1')->and($tally['rows'][0]['total'])->toBe(4.0);
    $this->get(route('evaluation.report', $this->class))->assertOk()->assertViewHas('setRows', fn ($rows) => $rows->first()['section'] === 'BSIT 3A' && $rows->sum('count') === 1);
    // Later membership changes must not reclassify the already recorded response.
    $section->update(['name' => 'BSIT 3B']);
    expect(app(EvaluationSections::class)->label($response->fresh(), $this->class))->toBe('BSIT 3A');
});

it('shows enrolment matching on the current Teaching Classes screen', function () {
    $preview = $this->service->preview($this->actor, $this->class, $this->subject);
    Livewire::test(StudentsRelationManager::class, ['ownerRecord' => $this->class, 'pageClass' => EditTeachingClass::class])
        ->mountTableAction('matchEnrolledStudents')->assertSee('Enrolled subject')->assertSee('Section pending')->assertHasNoTableActionErrors()
        ->setTableActionData(['subject_key' => $this->subject, 'use_clear_matches' => true, 'selected_ids' => [], 'programs' => [], 'review_hash' => $preview['hash'], 'confirmed' => true])
        ->callMountedTableAction()->assertHasNoTableActionErrors();
    expect($this->class->students()->whereKey($this->student->id)->exists())->toBeTrue();
});

it('assigns a reporting section through the evaluator bulk action', function () {
    $this->class->students()->attach($this->student, ['enrolled_at' => now()]);
    $section = AcademicSection::create(['name' => 'BSIT 3A', 'department_id' => $this->class->course->department_id, 'semester_id' => $this->class->semester_id, 'is_active' => true]);
    Livewire::test(StudentsRelationManager::class, ['ownerRecord' => $this->class, 'pageClass' => EditTeachingClass::class])
        ->callTableBulkAction('assignReportSection', [$this->student], data: ['section_id' => $section->id])->assertHasNoTableBulkActionErrors();
    expect($this->class->fresh()->evaluator_selection['report_sections'][$this->student->id])->toBe('BSIT 3A');
});

it('rejects different subject titles and students who are not enrolled in the reviewed subject', function () {
    $p = $this->service->preview($this->actor, $this->class, $this->subject);
    $outsider = User::factory()->create(['is_student' => true]);
    $outsider->campuses()->attach($this->campus);
    expect(fn () => $this->service->assign($this->actor, $this->class, $this->subject, [], [$outsider->id], $p['hash']))->toThrow(HttpException::class);
    StudentSubjectEnrolment::query()->update(['description' => 'Another subject with a reused code']);
    expect(fn () => $this->service->preview($this->actor, $this->class, $this->subject))->toThrow(HttpException::class);
    expect($this->class->students()->count())->toBe(0);
});

it('keeps sections separate in Annex C and section totals while protecting known historical labels', function () {
    $this->class->students()->attach($this->student, ['enrolled_at' => now()]);
    $response = FormSubmission::create(['course_offering_id' => $this->class->id, 'form_id' => $this->form->id,
        'submission_fingerprint' => app(EvaluationSubmissionIdentity::class)->forUser($this->class, $this->student->id),
        'data' => ['teaching_quality' => 5, '_evaluation_context' => ['section' => 'BSIT 3A']]]);
    FormSubmission::create(['course_offering_id' => $this->class->id, 'form_id' => $this->form->id,
        'data' => ['teaching_quality' => 1, '_evaluation_context' => ['section' => 'BSIT 3B']]]);
    $section = AcademicSection::create(['name' => 'BSIT 3C', 'department_id' => $this->class->course->department_id, 'semester_id' => $this->class->semester_id, 'is_active' => true]);
    expect(app(EvaluationSections::class)->assign($this->actor, $this->class, [$this->student->id], $section->id)['responses'])->toBe(0)
        ->and($response->fresh()->data['_evaluation_context']['section'])->toBe('BSIT 3A');
    $this->get(route('evaluation.report', $this->class))->assertOk()->assertViewHas('setRows', fn ($rows) => $rows->pluck('section')->all() === ['BSIT 3A', 'BSIT 3B'] && $rows->sum('count') === 2);
    $tally = app(EvaluationTally::class)->build($this->class, $this->class->formSubmissions()->get());
    expect(array_column($tally['section_summary'], 'section'))->toBe(['BSIT 3A', 'BSIT 3B'])
        ->and($tally['summary']['mean_average'])->toBe(3.0);
});

it('lets supervisor evaluators be configured before student sections are known', function () {
    Livewire::test(StudentsRelationManager::class, ['ownerRecord' => $this->class, 'pageClass' => EditTeachingClass::class])
        ->callTableAction('setSupervisors', data: ['supervisor_ids' => [$this->actor->id]])->assertHasNoTableActionErrors();
    expect($this->class->supervisorEvaluators()->whereKey($this->actor->id)->exists())->toBeTrue()
        ->and($this->student->studentSectionMemberships()->count())->toBe(0);
});

it('loads large enrolled cohorts with a bounded number of queries', function () {
    $students = User::factory()->count(120)->create(['is_student' => true, 'student_department_id' => $this->class->course->department_id]);
    $this->campus->users()->attach($students->modelKeys());
    $role = Role::findByName('student');
    DB::table('model_has_roles')->insert($students->map(fn ($s) => ['role_id' => $role->id, 'model_type' => User::class, 'model_id' => $s->id])->all());
    $rows = $students->map(fn ($s) => ['user_id' => $s->id, 'semester_id' => $this->class->semester_id, 'subject_key' => $this->subject,
        'subject_code' => $this->class->course->code, 'description' => $this->class->course->name, 'units' => 3])->all();
    foreach (array_chunk($rows, 50) as $chunk) {
        DB::table('student_subject_enrolments')->insert($chunk);
    }
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $p = $this->service->preview($this->actor, $this->class, $this->subject);
        expect($p['automatic_ids'])->toHaveCount(121)->and(count(DB::getQueryLog()))->toBeLessThan(45);
    } finally {
        DB::disableQueryLog();
    }
});
