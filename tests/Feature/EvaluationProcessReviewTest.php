<?php

use App\Models\Department;
use App\Models\EvaluationCycle;
use App\Models\FormSubmission;
use App\Models\StudentAcademicRecord;
use App\Models\User;
use App\Services\AcademicSections;
use App\Services\AcademicStudents;
use App\Services\BackgroundTasks;
use App\Services\EvaluationCopies;
use App\Services\EvaluationCycles;
use App\Services\EvaluationTemplates;
use App\Services\FacultySupervisorEvaluators;
use App\Services\ReportService;
use App\Services\StudentDeletion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function reviewedEvaluationFlow(): array
{
    ['offering' => $class, 'student' => $student] = evaluationFixture();
    $campus = $class->campus;
    $department = $class->course->department;
    $staff = function (string $role, ?int $departmentId = null) use ($campus) {
        $user = User::factory()->create(['is_student' => false, 'instructor_department_id' => $departmentId]);
        $user->assignRole(Role::findOrCreate($role, 'web'));
        $user->campuses()->attach($campus);

        return $user;
    };
    $owner = $staff('super_admin');
    $admin = $staff('campus_admin');
    DB::table('user_campus_access')->insert(['user_id' => $admin->id, 'campus_id' => $campus->id, 'scope' => 'campus']);
    $head = $staff('dean', $department->id);
    $chair = $staff('chairperson', $department->id);
    $otherDepartment = Department::create(['campus_id' => $campus->id, 'code' => 'CTAS', 'name' => 'CTAS', 'is_active' => true]);
    $extra = $staff('dean', $otherDepartment->id);
    foreach ([$owner, $admin, $head] as $user) {
        foreach (['manage_evaluators_course::offering', 'update_course::offering', 'page_EvaluationResults', 'manage_academic_students', 'view_academic_students', 'delete_academic_students'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
    }
    $department->update(['supervisor_id' => $head->id, 'chairperson_id' => $chair->id]);
    $faculty = $class->evaluatee;
    $faculty->campuses()->attach($campus);
    $faculty->assignRole(Role::findOrCreate('instructor', 'web'));
    $faculty->givePermissionTo(Permission::findOrCreate('page_MyEvaluationResults', 'web'));
    $student->update(['student_department_id' => $department->id, 'student_id' => 'PRIVATE-REVIEW-STUDENT']);
    $pending = User::factory()->create(['is_student' => true, 'student_department_id' => $department->id]);
    $pending->assignRole('student');
    $pending->campuses()->attach($campus);
    foreach ([$student, $pending] as $user) {
        StudentAcademicRecord::create(['user_id' => $user->id, 'semester_id' => $class->semester_id, 'program' => 'BSIT']);
    }
    $section = app(AcademicSections::class)->save($head, $campus, ['name' => 'BSIT 3A', 'department_id' => $department->id, 'semester_id' => $class->semester_id, 'is_active' => true]);
    $class->update(['form_id' => null]);
    app(AcademicSections::class)->add($head, $campus, $section, [$student->id, $pending->id]);
    app(AcademicStudents::class)->assignSections($head, $class, [$department->id.'~'.$class->semester_id.'~BSIT%203A']);
    app(FacultySupervisorEvaluators::class)->save($head, $class, [$head->id, $extra->id]);
    $a = app(EvaluationTemplates::class)->create('A', $campus);
    $b = app(EvaluationTemplates::class)->create('B', $campus);
    foreach ([$a, $b] as $form) {
        $form->departments()->attach($department);
    }
    $cycles = app(EvaluationCycles::class);
    $cycle = $cycles->save($admin, $campus, ['name' => 'Reviewed faculty evaluation', 'department_id' => $department->id,
        'semester_id' => $class->semester_id, 'student_form_id' => $a->id, 'supervisor_form_id' => $b->id,
        'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(2), 'assignment_ids' => [$class->id]]);
    $cycles->publish($admin, $cycle);
    $task = $cycle->assignments()->where('evaluation_type', 'SEF')->sole();

    return compact('class', 'student', 'pending', 'campus', 'department', 'owner', 'admin', 'head', 'chair', 'extra', 'faculty', 'a', 'b', 'cycle', 'task');
}

function submitReviewedResponses($test, array $flow): void
{
    extract($flow);
    $cases = [[$student, $class, $a, 4, 'Student overall feedback'], [$head, $task, $b, 5, 'Primary supervisor feedback'], [$extra, $task, $b, 3, 'Additional supervisor feedback']];
    foreach ($cases as [$user, $assignment, $form, $rating, $comment]) {
        $answers = $form->fields()->pluck('name')->mapWithKeys(fn ($name) => [$name => $rating])->all();
        $test->actingAs($user)->get(route('evaluate.course-offering', $assignment))->assertOk();
        $test->post(route('evaluate.store', $assignment), ['fields' => $answers, 'overall_comment' => $comment])->assertRedirect(route('evaluate.success', $assignment));
    }
}

it('keeps a signed-in supervisor identity when a previous student evaluation session remains in the browser', function () {
    extract(reviewedEvaluationFlow());
    $this->withSession(['evaluation.student_id' => $student->id, 'evaluation.expires_at' => now()->addMinutes(30)->timestamp]);
    $this->actingAs($head)->get(route('evaluate.course-offering', $task))->assertOk();
    $answers = $b->fields()->pluck('name')->mapWithKeys(fn ($name) => [$name => 4])->all();
    $this->post(route('evaluate.store', $task), ['fields' => $answers, 'overall_comment' => 'Supervisor session check'])
        ->assertRedirect(route('evaluate.success', $task));
    $submission = $task->formSubmissions()->sole();
    expect($submission->evaluator_identity['name'])->toBe($head->name)
        ->and($class->formSubmissions()->count())->toBe(0);
});

it('includes primary and additional supervisor scores, comments, tally, CSV and individual Annex B copies', function () {
    $flow = reviewedEvaluationFlow();
    extract($flow);
    Storage::fake('local');
    submitReviewedResponses($this, $flow);
    $this->actingAs($admin);
    foreach (['C', 'D'] as $annex) {
        $response = $this->get(route('evaluation.report', ['offering' => $class, 'annex' => $annex]))->assertOk()
            ->assertSee('2 completed supervisor response(s)')->assertDontSee('PRIVATE-REVIEW-STUDENT')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        expect($response->viewData('ratings')->all())->toBe(['SET' => 80.0, 'SEF' => 80.0]);
        if (getenv('RENDER_EVALUATION_REVIEW_QA')) {
            file_put_contents(storage_path('app/maintenance/evaluation-review-annex-'.strtolower($annex).'.html'), $response->getContent());
        }
    }
    $this->get(route('evaluation.report', $class))->assertSee('Primary supervisor feedback')->assertSee('Additional supervisor feedback')->assertSee('BSIT 3A');
    foreach (['scores', 'tally'] as $kind) {
        $response = $this->get(route('evaluation.tabular-report', ['offering' => $class, 'kind' => $kind]))->assertOk()
            ->assertDontSee($student->name)->assertDontSee($head->name)->assertDontSee($extra->name);
        $sef = $response->viewData('reports')->firstWhere('form.id', $b->id);
        expect($sef['score']['count'])->toBe(2)->and($sef['score']['percentage'])->toBe(80.0)
            ->and(array_column($sef['tally']['rows'], 'total'))->toBe([75.0, 45.0])
            ->and($sef['tally']['summary']['mean_average'])->toBe(4.0);
        if (getenv('RENDER_EVALUATION_REVIEW_QA')) {
            file_put_contents(storage_path('app/maintenance/evaluation-review-'.$kind.'.html'), $response->getContent());
        }
    }
    $context = ['campus_id' => $campus->id, 'semester_id' => $class->semester_id, 'evaluation_cycle_id' => $cycle->id];
    expect(app(ReportService::class)->getEvaluateeSummary($faculty, null, null, $context)['completion_rate'])->toBe(50.0);
    $job = app(BackgroundTasks::class)->enqueue($admin, $campus, 'faculty-export', 'Reviewed results', ['ids' => [$faculty->id], 'context' => $context]);
    app(BackgroundTasks::class)->run($job->id);
    expect($job->fresh()->status)->toBe('completed', $job->fresh()->error ?? '');
    expect(Storage::disk('local')->get($job->fresh()->file_path))->toContain('SET,4.00,80.00,1')->toContain('SEF,4.00,80.00,2');
    $ids = FormSubmission::whereIn('course_offering_id', [$class->id, $task->id])->pluck('id')->all();
    $url = app(EvaluationCopies::class)->previewUrl($admin, $campus, $ids);
    $copies = $this->get($url)->assertOk()->assertSee('SUPERVISOR’S EVALUATION OF FACULTY')->assertSee('100.00%')->assertSee('60.00%')
        ->assertDontSee($student->name)->assertDontSee($head->name)->assertDontSee($extra->name);
    expect($copies->viewData('copies'))->toHaveCount(3);
    if (getenv('RENDER_EVALUATION_REVIEW_QA')) {
        file_put_contents(storage_path('app/maintenance/evaluation-review-copies.html'), $copies->getContent());
    }
});

it('keeps the paired supervisor results in Annex C/D when filtering a student form without mixing another cycle', function () {
    $flow = reviewedEvaluationFlow();
    extract($flow);
    submitReviewedResponses($this, $flow);
    $otherCycle = EvaluationCycle::create(['campus_id' => $campus->id, 'department_id' => $department->id, 'semester_id' => $class->semester_id, 'name' => 'Another evaluation', 'status' => 'closed']);
    $otherTask = $task->replicate();
    $otherTask->evaluation_cycle_id = $otherCycle->id;
    $otherTask->save();
    FormSubmission::create(['course_offering_id' => $otherTask->id, 'form_id' => $b->id, 'data' => $b->fields->mapWithKeys(fn ($field) => [$field->name => 1])->all()]);
    $this->actingAs($admin);
    foreach (['C', 'D'] as $annex) {
        $response = $this->get(route('evaluation.report', ['offering' => $class, 'annex' => $annex, 'form' => $a->id]))->assertOk();
        expect($response->viewData('ratings')['SEF'])->toBe(80.0)->and($response->viewData('rows')->sum('count'))->toBe(3);
    }
    $response = $this->get(route('evaluation.tabular-report', ['offering' => $class, 'kind' => 'scores', 'form' => $a->id]))->assertOk();
    expect($response->viewData('reports'))->toHaveCount(1);
});

it('retains student and supervisor results after student deletion and class deactivation and releases them after closure', function () {
    $flow = reviewedEvaluationFlow();
    extract($flow);
    submitReviewedResponses($this, $flow);
    config(['evaluations.min_responses_for_results' => 1]);
    $this->actingAs($faculty)->get(route('evaluation.report', $class))->assertForbidden();
    app(EvaluationCycles::class)->close($admin, $cycle->fresh());
    $this->get(route('evaluation.report', $class))->assertOk()->assertViewHas('ratings', fn ($ratings) => $ratings['SEF'] === 80.0);
    app(StudentDeletion::class)->delete($admin, $campus, [$student->id]);
    $class->update(['is_active' => false]);
    $this->actingAs($admin)->get(route('evaluation.report', ['offering' => $class, 'cycle' => $cycle->id]))->assertOk()
        ->assertViewHas('ratings', fn ($ratings) => $ratings['SET'] === 80.0 && $ratings['SEF'] === 80.0);
    expect(FormSubmission::whereIn('course_offering_id', [$class->id, $task->id])->count())->toBe(3);
});

it('grants an additional supervisor submission access without granting foreign-department reporting authority', function () {
    $flow = reviewedEvaluationFlow();
    extract($flow);
    $extra->givePermissionTo(Permission::findOrCreate('page_EvaluationResults', 'web'));
    $this->actingAs($extra)->get(route('evaluate.course-offering', $task))->assertOk();
    $this->get(route('evaluation.report', $task))->assertForbidden();
    $this->get(route('evaluation.tabular-report', ['offering' => $task, 'kind' => 'tally']))->assertForbidden();
});
