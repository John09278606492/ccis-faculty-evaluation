<?php

use App\Livewire\FacultySectioningPanel;
use App\Models\AcademicSection;
use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\FormSubmission;
use App\Models\StudentSectionMembership;
use App\Models\TeachingClassSourceLink;
use App\Models\User;
use App\Services\AutomaticEvaluatorAssignments;
use App\Services\BackgroundTasks;
use App\Services\EvaluationAccessService;
use App\Services\EvaluationSubmissionIdentity;
use App\Services\FacultySectioning;
use App\Services\StudentAcademicRecords;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

function importedAutomaticClass(CourseOffering $class): void
{
    $class->update(['form_id' => null, 'evaluation_cycle_id' => null, 'evaluator_selection' => ['source' => 'sections_v1', 'auto_section_roster' => true, 'sections' => []]]);
    TeachingClassSourceLink::create(['campus_id' => $class->campus->id, 'course_offering_id' => $class->id, 'source_key' => hash('sha256', 'automatic-'.$class->id),
        'metadata' => ['rows' => [['code' => 'BISUBAL-BSIT-2023']]]]);
}

beforeEach(function () {
    ['offering' => $this->class, 'student' => $this->student, 'form' => $this->form] = evaluationFixture(false);
    $this->campus = $this->class->campus;
    $this->actor = User::factory()->create(['is_student' => false]);
    $this->actor->assignRole('super_admin');
    $this->actor->campuses()->attach($this->campus);
    foreach (['manage_evaluators_course::offering', 'update_course::offering', 'view_course::offering', 'view_any_course::offering',
        'view_academic_students', 'manage_academic_students', 'import_academic_students', 'create_course::offering'] as $p) {
        $this->actor->givePermissionTo(Permission::findOrCreate($p, 'web'));
    }
    $this->student->update(['student_id' => 'AUTO-001', 'student_department_id' => $this->class->course->department_id]);
    app(StudentAcademicRecords::class)->save($this->student, $this->class->semester_id, ['program' => 'BSIT', 'subjects' => [
        ['subject_code' => $this->class->course->code, 'description' => $this->class->course->name, 'units' => 3],
    ]]);
    $this->actingAs($this->actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    importedAutomaticClass($this->class);
    $this->service = app(AutomaticEvaluatorAssignments::class);
    Queue::fake();
});

it('automatically assigns enrolled students after upload without requiring a saved section', function () {
    $r = $this->service->synchronize($this->actor, $this->campus, $this->class->semester_id);
    expect($r['assigned'])->toBe(1)->and($r['unresolved'])->toBe(0)->and($this->class->students()->count())->toBe(1)
        ->and($this->class->fresh()->evaluator_selection['auto_match']['pending_section'])->toBe(1);
    expect($this->service->synchronize($this->actor, $this->campus, $this->class->semester_id)['assigned'])->toBe(0)
        ->and($this->class->students()->count())->toBe(1);
});

it('uses one shared faculty subject roster across unknown sections without duplicate evaluations', function () {
    $b = $this->class->replicate();
    $b->section = 'B';
    $b->save();
    importedAutomaticClass($b);
    $this->service->synchronize($this->actor, $this->campus, $this->class->semester_id);
    $this->service->synchronize($this->actor, $this->campus, $this->class->semester_id);
    expect($this->class->students()->count() + $b->students()->count())->toBe(1)
        ->and($b->fresh()->evaluator_selection['auto_match']['grouped_count'])->toBe(1)
        ->and($b->fresh()->evaluator_selection['auto_match']['elsewhere'])->toBe(0);
});

it('requires sectioning instead of guessing for subjects handled by multiple instructors', function () {
    $other = $this->class->replicate();
    $other->section = 'B';
    $other->user_id = User::factory()->create(['is_instructor' => true])->id;
    $other->save();
    importedAutomaticClass($other);
    $r = $this->service->synchronize($this->actor, $this->campus, $this->class->semester_id);
    expect($r['assigned'])->toBe(0)->and($r['unresolved'])->toBe(1)->and($this->class->fresh()->evaluator_selection['auto_match']['ambiguous'])->toBe(1);
    $section = AcademicSection::create(['name' => 'A', 'department_id' => $this->class->course->department_id, 'semester_id' => $this->class->semester_id, 'is_active' => true]);
    StudentSectionMembership::create(['academic_section_id' => $section->id, 'department_id' => $section->department_id, 'semester_id' => $section->semester_id, 'section' => 'A', 'user_id' => $this->student->id]);
    $r = $this->service->synchronize($this->actor, $this->campus, $this->class->semester_id);
    expect($r['assigned'])->toBe(1)->and($r['unresolved'])->toBe(0)->and($other->students()->count())->toBe(0);
});

it('moves only automatic unanswered assignments when the actual section becomes known', function () {
    $b = $this->class->replicate();
    $b->section = 'B';
    $b->save();
    importedAutomaticClass($b);
    $this->service->synchronize($this->actor, $this->campus, $this->class->semester_id);
    StudentSectionMembership::create(['department_id' => $this->class->course->department_id, 'semester_id' => $this->class->semester_id, 'section' => 'B', 'user_id' => $this->student->id]);
    $r = $this->service->synchronize($this->actor, $this->campus, $this->class->semester_id);
    expect($r['moved'])->toBe(1)->and($this->class->students()->count())->toBe(0)->and($b->students()->count())->toBe(1);
});

it('preserves opted out classes manual choices and classes with submitted records', function () {
    $this->class->update(['evaluator_selection' => ['source' => 'sections_v1', 'auto_section_roster' => true, 'auto_enrolment' => false]]);
    expect($this->service->synchronize($this->actor, $this->campus, $this->class->semester_id)['assigned'])->toBe(0);
    $this->class->update(['evaluator_selection' => ['source' => 'sections_v1', 'auto_section_roster' => true], 'form_id' => $this->form->id]);
    $response = FormSubmission::create(['course_offering_id' => $this->class->id, 'form_id' => $this->form->id, 'data' => ['teaching_quality' => 4]]);
    expect($this->service->synchronize($this->actor, $this->campus, $this->class->semester_id)['assigned'])->toBe(0)
        ->and($response->fresh()->data)->toBe(['teaching_quality' => 4]);
});

it('configures section and instructor in place and validates membership against enrolled subjects', function () {
    $other = $this->class->replicate();
    $other->section = 'B';
    $other->user_id = User::factory()->create(['is_instructor' => true])->id;
    $other->save();
    importedAutomaticClass($other);
    $sectionId = app(FacultySectioning::class)->createSection($this->actor, $this->campus, $this->class->id, 'BSIT 3A');
    $r = app(FacultySectioning::class)->assign($this->actor, $this->campus, ['anchor_id' => $this->class->id, 'faculty_id' => $this->class->user_id, 'section_id' => $sectionId], [$this->student->id]);
    $target = CourseOffering::findOrFail($r['class_id']);
    expect($target->students()->whereKey($this->student->id)->exists())->toBeTrue()->and($other->students()->count())->toBe(0)
        ->and($target->evaluator_selection['report_sections'][$this->student->id])->toBe('BSIT 3A');
});

it('keeps valid selection and background completion within the same sectioning component', function () {
    $section = AcademicSection::create(['name' => 'A', 'department_id' => $this->class->course->department_id, 'semester_id' => $this->class->semester_id, 'is_active' => true]);
    $ui = Livewire::test(FacultySectioningPanel::class, ['anchorId' => $this->class->id, 'campusId' => $this->campus->id])
        ->fillForm(['faculty_id' => $this->class->user_id, 'section_id' => $section->id, 'mode' => 'pick', 'student_ids' => [$this->student->id], 'confirmed' => true])
        ->call('assign')->assertHasNoFormErrors()->assertSet('operationStatus', 'queued');
    $taskId = $ui->get('operationId');
    app(BackgroundTasks::class)->run($taskId);
    $ui->call('refreshOperation')->assertSet('operationStatus', 'completed')->assertSee('students assigned')->call('anotherSection')->assertSet('operationId', null)->assertSee('Instructor');
    expect($this->class->students()->count())->toBe(1);
});

it('rejects an invalid uploaded section list without partial memberships and shows issues in place', function () {
    Storage::fake('local');
    $section = AcademicSection::create(['name' => 'A', 'department_id' => $this->class->course->department_id, 'semester_id' => $this->class->semester_id, 'is_active' => true]);
    $task = app(BackgroundTasks::class)->upload($this->actor, $this->campus, UploadedFile::fake()->createWithContent('section.csv', "ID No.\nAUTO-001\nNOT-IN-MASTERLIST\n"),
        'sectioning-import', 'Assign uploaded section', ['anchor_id' => $this->class->id, 'faculty_id' => $this->class->user_id, 'section_id' => $section->id]);
    app(BackgroundTasks::class)->run($task->id);
    $task->refresh();
    expect($task->status)->toBe('completed', $task->error ?? '')->and($task->result['validation_error_count'])->toBe(1)
        ->and($section->students()->count())->toBe(0)->and($this->class->students()->count())->toBe(0)
        ->and(Storage::disk('local')->exists($task->file_path))->toBeTrue();
});

it('blocks student evaluation of multi instructor subjects until their section is recorded', function () {
    $other = $this->class->replicate();
    $other->user_id = User::factory()->create(['is_instructor' => true])->id;
    $other->section = 'B';
    $other->save();
    $this->class->update(['form_id' => $this->form->id]);
    $this->class->students()->attach($this->student);
    expect(fn () => app(EvaluationAccessService::class)->authorize($this->class->fresh(), $this->student))->toThrow(AuthorizationException::class);
    StudentSectionMembership::create(['department_id' => $this->class->course->department_id, 'semester_id' => $this->class->semester_id, 'section' => 'UNRELATED', 'user_id' => $this->student->id]);
    expect(fn () => app(EvaluationAccessService::class)->authorize($this->class->fresh(), $this->student))->toThrow(AuthorizationException::class);
    $this->class->update(['evaluator_selection' => ['report_sections' => [$this->student->id => 'A']]]);
    expect(app(EvaluationAccessService::class)->authorize($this->class->fresh(), $this->student)->id)->toBe($this->form->id);
});

it('validates and assigns a valid uploaded section list through the background worker', function () {
    Storage::fake('local');
    $section = AcademicSection::create(['name' => 'A', 'department_id' => $this->class->course->department_id, 'semester_id' => $this->class->semester_id, 'is_active' => true]);
    $task = app(BackgroundTasks::class)->upload($this->actor, $this->campus, UploadedFile::fake()->createWithContent('section.csv', "ID No.\nAUTO-001\n"),
        'sectioning-import', 'Assign uploaded section', ['anchor_id' => $this->class->id, 'faculty_id' => $this->class->user_id, 'section_id' => $section->id]);
    app(BackgroundTasks::class)->run($task->id);
    $task->refresh();
    expect($task->status)->toBe('completed', $task->error ?? '')->and($task->result['validation_error_count'])->toBe(0)
        ->and($section->students()->count())->toBe(1)->and($this->class->students()->count())->toBe(1);
});

it('does not expand an explicit section roster to unrelated enrolled students', function () {
    $unselected = User::factory()->create(['is_student' => true, 'is_instructor' => false, 'student_id' => 'NOT-SELECTED',
        'student_department_id' => $this->class->course->department_id]);
    $unselected->campuses()->attach($this->campus);
    $unselected->assignRole('student');
    app(StudentAcademicRecords::class)->save($unselected, $this->class->semester_id, ['program' => 'BSIT', 'subjects' => [
        ['subject_code' => $this->class->course->code, 'description' => $this->class->course->name, 'units' => 3],
    ]]);
    $section = AcademicSection::create(['name' => $this->class->section, 'department_id' => $this->class->course->department_id,
        'semester_id' => $this->class->semester_id, 'is_active' => true]);
    app(FacultySectioning::class)->assign($this->actor, $this->campus,
        ['anchor_id' => $this->class->id, 'faculty_id' => $this->class->user_id, 'section_id' => $section->id], [$this->student->id]);
    $this->service->synchronize($this->actor, $this->campus, $this->class->semester_id);

    expect($this->class->students()->pluck('users.id')->all())->toBe([$this->student->id]);
});

it('preserves completed anonymous answers and their instructor while recording a pending section', function () {
    $this->class->update(['form_id' => $this->form->id]);
    $this->class->students()->attach($this->student);
    $response = FormSubmission::create(['course_offering_id' => $this->class->id, 'form_id' => $this->form->id,
        'submission_fingerprint' => app(EvaluationSubmissionIdentity::class)->forUser($this->class, $this->student->id),
        'data' => ['teaching_quality' => 4, 'overall_comments' => 'Keep the scores.', '_evaluation_context' => ['section' => null]], 'submitted_at' => now()]);
    $before = $response->submitted_at->toIso8601String();
    $sectionId = app(FacultySectioning::class)->createSection($this->actor, $this->campus, $this->class->id, 'BSIT 3A');
    $result = app(FacultySectioning::class)->assign($this->actor, $this->campus, ['anchor_id' => $this->class->id, 'faculty_id' => $this->class->user_id, 'section_id' => $sectionId], [$this->student->id]);
    expect($response->fresh()->data['teaching_quality'])->toBe(4)->and($response->fresh()->data['overall_comments'])->toBe('Keep the scores.')
        ->and($response->fresh()->data['_evaluation_context']['section'])->toBe('BSIT 3A')
        ->and($response->fresh()->submitted_at->toIso8601String())->toBe($before)
        ->and($this->class->students()->count())->toBe(1)->and(CourseOffering::findOrFail($result['class_id'])->students()->count())->toBe(0);
});

it('does not expose another campus or permit sectioning without student management access', function () {
    $campus = Campus::create(['name' => 'Other', 'slug' => 'other', 'is_active' => true]);
    expect(fn () => app(FacultySectioning::class)->anchor($this->actor, $campus, $this->class->id))->toThrow(HttpException::class);
    $this->actor->removeRole('super_admin');
    $this->actor->revokePermissionTo('manage_academic_students');
    expect(app(FacultySectioning::class)->allowed($this->actor->fresh(), $this->campus))->toBeFalse();
});
