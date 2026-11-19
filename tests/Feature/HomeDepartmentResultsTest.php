<?php

use App\Models\Department;
use App\Models\FormSubmission;
use App\Models\User;
use App\Services\AssignedDataAccess;
use App\Services\EvaluationResultsRelease;
use App\Services\ReportService;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Permission;

it('lets home department leadership read visiting faculty results without managing their classes or seeing unrelated faculty', function (string $leadership) {
    ['offering' => $class, 'student' => $student] = evaluationFixture();
    $campus = $class->campus;
    $home = Department::create(['campus_id' => $campus->id, 'name' => 'GenEd', 'code' => 'GENED', 'is_active' => true]);
    $head = User::factory()->create(['is_student' => false, 'is_instructor' => true, 'instructor_department_id' => $home->id]);
    $head->campuses()->attach($campus);
    $head->assignRole(Spatie\Permission\Models\Role::findOrCreate('chairperson', 'web'));
    $head->givePermissionTo(Permission::findOrCreate('page_EvaluationResults', 'web'));
    $home->update([$leadership => $head->id]);
    $teacher = $class->evaluatee;
    $teacher->update(['instructor_department_id' => $home->id]);
    $teacher->campuses()->syncWithoutDetaching([$campus->id]);
    $class->update(['teaching_department_id' => $class->course->department_id]);
    $response = FormSubmission::create(['form_id' => $class->form_id, 'course_offering_id' => $class->id,
        'submission_fingerprint' => app(App\Services\EvaluationSubmissionIdentity::class)->forUser($class, $student->id),
        'data' => ['teaching_quality' => 4]]);
    $outsider = User::factory()->create(['is_student' => false, 'is_instructor' => true, 'instructor_department_id' => $class->course->department_id]);
    $outsider->campuses()->attach($campus);
    $other = $class->replicate(); $other->user_id = $outsider->id; $other->save();
    $this->actingAs($head);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($campus, isQuiet: true);
    $access = app(AssignedDataAccess::class); $access->actor = $head;
    expect($access->reviewsOffering($head, $class))->toBeTrue()
        ->and($access->managesOffering($head, $class))->toBeFalse()
        ->and(EvaluationResultsRelease::canReview($head, $class))->toBeTrue()
        ->and($access->reviewsOffering($head, $other))->toBeFalse()
        ->and(App\Models\CourseOffering::whereKey($class->id)->exists())->toBeTrue()
        ->and(App\Models\CourseOffering::whereKey($other->id)->exists())->toBeFalse()
        ->and(FormSubmission::whereKey($response->id)->exists())->toBeTrue();
    $context = ['campus_id' => $campus->id, 'semester_id' => $class->semester_id, 'department_id' => $home->id];
    expect(app(ReportService::class)->offerings($context)->pluck('course_offerings.id')->all())->toBe([$class->id]);
    $summary = app(ReportService::class)->getEvaluateeSummary($teacher, null, null, $context);
    expect($summary['total_submissions'])->toBe(1);
    expect(User::whereKey($student->id)->exists())->toBeFalse()
        ->and($summary['total_students'])->toBe(1)
        ->and($summary['completed_students'])->toBe(1)
        ->and($summary['completion_rate'])->toBe(100.0)
        ->and(app(ReportService::class)->assignmentCompletion($class)['percentage'])->toBe(100.0);
    $home->update([$leadership => null]);
    expect($access->reviewsOffering($head, $class))->toBeFalse();
})->with(['chairperson_id', 'supervisor_id']);

it('counts historical roster members once and ignores submissions from another form', function () {
    ['offering' => $class, 'student' => $student] = evaluationFixture();
    $legacy = User::factory()->create(['is_student' => true]);
    $pending = User::factory()->create(['is_student' => true]);
    $class->students()->attach([$legacy->id, $pending->id]);
    FormSubmission::create(['form_id' => $class->form_id, 'course_offering_id' => $class->id, 'user_id' => $student->id,
        'submission_fingerprint' => app(App\Services\EvaluationSubmissionIdentity::class)->forUser($class, $student->id), 'data' => ['teaching_quality' => 5]]);
    FormSubmission::create(['form_id' => $class->form_id, 'course_offering_id' => $class->id, 'user_id' => $legacy->id, 'data' => ['teaching_quality' => 4]]);
    $otherForm = $class->evaluationForm->replicate(); $otherForm->slug = 'older-instrument'; $otherForm->save();
    FormSubmission::create(['form_id' => $otherForm->id, 'course_offering_id' => $class->id, 'user_id' => $pending->id, 'data' => ['teaching_quality' => 5]]);
    $legacy->delete();
    expect(app(ReportService::class)->assignmentCompletion($class))->toBe(['expected' => 3, 'completed' => 2, 'percentage' => 66.7]);
});
