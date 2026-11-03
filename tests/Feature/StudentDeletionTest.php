<?php

use App\Filament\Pages\Login;
use App\Filament\Resources\AcademicStudentResource;
use App\Filament\Resources\AcademicStudentResource\Pages\EditAcademicStudent;
use App\Filament\Resources\AcademicStudentResource\Pages\ListAcademicStudents;
use App\Models\Campus;
use App\Models\Department;
use App\Models\FormSubmission;
use App\Models\Rating;
use App\Models\RatingCriteria;
use App\Models\User;
use App\Services\AcademicStudentImports;
use App\Services\AcademicStudents;
use App\Services\EvaluationAccessService;
use App\Services\EvaluationCopies;
use App\Services\EvaluationScoring;
use App\Services\EvaluationSubmissionIdentity;
use App\Services\EvaluationTemplates;
use App\Services\ReportService;
use App\Services\StudentDeletion;
use App\Services\StudentPins;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    ['offering' => $this->assignment, 'student' => $this->student] = evaluationFixture();
    $this->campus = $this->assignment->campus;
    $this->department = $this->assignment->course->department;
    $this->actor = $this->assignment->instructor;
    $this->actor->campuses()->attach($this->campus);
    foreach (['view_academic_students', 'manage_academic_students', 'delete_academic_students', 'import_academic_students', 'page_EvaluationResults', 'view_evaluator_identity_form'] as $permission) {
        $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->student->update(['student_id' => 'KEEP-001', 'student_department_id' => $this->department->id]);
    $this->form = app(EvaluationTemplates::class)->create('A', $this->campus);
    $this->assignment->update(['form_id' => $this->form->id]);
    $this->answers = [...$this->form->fields->mapWithKeys(fn ($field) => [$field->name => 4])->all(), '_overall_comment' => 'Keep this historical comment.'];
    $this->response = FormSubmission::create(['course_offering_id' => $this->assignment->id, 'form_id' => $this->form->id,
        'user_id' => $this->student->id, 'data' => $this->answers]);
    $this->actingAs($this->actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    $this->deletion = app(StudentDeletion::class);
});

it('deletes a student through the table while preserving legacy ratings and every evaluation result', function () {
    $criterion = RatingCriteria::create(['campus_id' => $this->campus->id, 'name' => 'Legacy teaching', 'weight' => 1]);
    $rating = Rating::create(['course_offering_id' => $this->assignment->id, 'user_id' => $this->student->id, 'rating_criteria_id' => $criterion->id, 'score' => 5]);
    $score = app(EvaluationScoring::class)->assignment($this->assignment);
    $report = app(ReportService::class)->getEvaluateeSummary($this->assignment->evaluatee);
    $completion = app(ReportService::class)->assignmentCompletion($this->assignment);
    $original = $this->response->fresh()->getRawOriginal();

    Livewire::test(ListAcademicStudents::class)->callTableAction('delete', $this->student)->assertHasNoTableActionErrors()
        ->assertCanNotSeeTableRecords([$this->student]);

    $this->assertSoftDeleted('users', ['id' => $this->student->id]);
    expect(User::find($this->student->id))->toBeNull()
        ->and($this->response->fresh()->getRawOriginal())->toBe($original)
        ->and($rating->fresh()->score)->toBe(5)
        ->and($rating->fresh()->user->id)->toBe($this->student->id)
        ->and(app(EvaluationScoring::class)->assignment($this->assignment->fresh()))->toEqual($score)
        ->and(app(ReportService::class)->assignmentCompletion($this->assignment->fresh()))->toBe($completion);
    $after = app(ReportService::class)->getEvaluateeSummary($this->assignment->evaluatee);
    foreach (['avg_score', 'total_submissions', 'complete_responses', 'completion_rate', 'completed_students', 'total_students', 'instruments'] as $key) {
        expect($after[$key])->toBe($report[$key]);
    }
    expect($this->assignment->fresh()->students()->count())->toBe(0)
        ->and($this->assignment->fresh()->historicalStudents()->count())->toBe(1);
    $this->assertDatabaseHas('course_offering_student', ['user_id' => $this->student->id, 'course_offering_id' => $this->assignment->id]);
    $this->assertDatabaseHas('access_audit_events', ['subject_id' => $this->student->id, 'actor_id' => $this->actor->id, 'action' => 'student.deleted']);
});

it('keeps anonymous fingerprint responses and nonrespondent historical counts in bulk deletion', function () {
    $pending = User::factory()->create(['is_student' => true, 'student_department_id' => $this->department->id]);
    $pending->assignRole('student');
    $pending->campuses()->attach($this->campus);
    $this->assignment->students()->attach($pending);
    $this->response->update(['user_id' => null, 'submission_fingerprint' => app(EvaluationSubmissionIdentity::class)->fingerprint($this->assignment, request(), $this->student)]);
    $before = app(ReportService::class)->assignmentCompletion($this->assignment->fresh());
    expect($before)->toBe(['expected' => 2, 'completed' => 1, 'percentage' => 50.0]);
    Livewire::test(ListAcademicStudents::class)->callTableBulkAction('delete', [$this->student, $pending])->assertHasNoTableBulkActionErrors();
    expect(User::whereKey([$this->student->id, $pending->id])->count())->toBe(0)
        ->and(app(ReportService::class)->assignmentCompletion($this->assignment->fresh()))->toBe($before)
        ->and($this->response->fresh()->data)->toBe($this->answers);
});

it('supports deletion from the student edit page', function () {
    Livewire::test(EditAcademicStudent::class, ['record' => $this->student->id])
        ->callAction('delete')->assertHasNoActionErrors()->assertRedirect(AcademicStudentResource::getUrl('index'));
    $this->assertSoftDeleted($this->student);
    expect($this->response->fresh())->not->toBeNull();
});

it('preserves printable anonymous and authorized identified copies after deletion', function () {
    $fingerprinted = $this->response->replicate();
    $fingerprinted->user_id = null;
    $fingerprinted->submission_fingerprint = app(EvaluationSubmissionIdentity::class)->fingerprint($this->assignment, request(), $this->student);
    $fingerprinted->save();
    $url = app(EvaluationCopies::class)->previewUrl($this->actor, $this->campus, [$this->response->id, $fingerprinted->id]);
    $this->deletion->delete($this->actor, $this->campus, [$this->student->id]);
    $this->get($url)->assertOk()->assertSee('Student 1')->assertSee('Student 2')->assertSee('Keep this historical comment.')->assertDontSee('KEEP-001');
    $identified = $this->get($url.'&identity=1')->assertOk()->assertSee('KEEP-001')->assertSee($this->student->name);
    expect(array_column($identified->viewData('copies')->all(), 'identity'))->toHaveCount(2);
    $this->actor->revokePermissionTo('view_evaluator_identity_form');
    $this->get($url.'&identity=1')->assertForbidden();
});

it('requires deletion permission even for a student manager', function () {
    $this->actor->revokePermissionTo('delete_academic_students');
    expect($this->deletion->allowed($this->actor, $this->campus, $this->student))->toBeFalse();
    Livewire::test(ListAcademicStudents::class)->assertTableActionHidden('delete', $this->student)->assertTableBulkActionHidden('delete');
    expect(fn () => $this->deletion->delete($this->actor, $this->campus, [$this->student->id]))->toThrow(HttpException::class);
    expect($this->student->fresh()->trashed())->toBeFalse();
});

it('does not let enrolment visibility authorize deletion in another home department and keeps a mixed bulk selection atomic', function () {
    $other = Department::create(['campus_id' => $this->campus->id, 'name' => 'Other department', 'code' => 'OTHER', 'is_active' => true]);
    $visitor = User::factory()->create(['is_student' => true, 'student_department_id' => $other->id]);
    $visitor->campuses()->attach($this->campus);
    $visitor->assignRole('student');
    $this->assignment->students()->attach($visitor);
    expect(app(AcademicStudents::class)->students($this->actor, $this->campus)->whereKey($visitor->id)->exists())->toBeTrue();
    expect(fn () => $this->deletion->delete($this->actor, $this->campus, [$this->student->id, $visitor->id]))->toThrow(HttpException::class);
    expect(User::whereKey([$this->student->id, $visitor->id])->count())->toBe(2);
    Livewire::test(ListAcademicStudents::class)->assertTableActionHidden('delete', $visitor);
});

it('rejects foreign campuses, multi-campus accounts and staff or privileged hybrids', function () {
    $other = Campus::create(['name' => 'Other campus', 'slug' => 'other-delete', 'is_active' => true]);
    expect(fn () => $this->deletion->delete($this->actor, $other, [$this->student->id]))->toThrow(HttpException::class);
    $this->student->campuses()->attach($other);
    expect(fn () => $this->deletion->delete($this->actor, $this->campus, [$this->student->id]))->toThrow(HttpException::class);
    $this->student->campuses()->detach($other);
    $this->student->assignRole('super_admin');
    expect(fn () => $this->deletion->delete($this->actor, $this->campus, [$this->student->id]))->toThrow(HttpException::class);
    $this->student->removeRole('super_admin');
    $this->student->update(['is_instructor' => true]);
    expect(fn () => $this->deletion->delete($this->actor, $this->campus, [$this->student->id]))->toThrow(HttpException::class);
    expect(fn () => $this->deletion->delete($this->actor, $this->campus, [$this->actor->id]))->toThrow(HttpException::class);
    expect($this->response->fresh())->not->toBeNull();
});

it('reserves deleted IDs and rejects imports reviewed before deletion', function () {
    $row = ['student_id' => $this->student->student_id, 'first_name' => 'New', 'last_name' => 'Person', 'department_code' => $this->department->code];
    $imports = app(AcademicStudentImports::class);
    $review = $imports->review($this->actor, $this->campus, [$row], $this->assignment->semester_id);
    expect($review['error_count'])->toBe(0);
    $this->deletion->delete($this->actor, $this->campus, [$this->student->id]);
    expect(fn () => $imports->commit($this->actor, $this->campus, $review['token'], $this->assignment->semester_id))->toThrow(ValidationException::class);
    $after = $imports->review($this->actor, $this->campus, [$row], $this->assignment->semester_id);
    expect($after['error_count'])->toBe(1)->and($after['new_students'])->toBe(0);
    expect(fn () => app(AcademicStudents::class)->import($this->actor, $this->campus, [$row], $this->assignment->semester_id))->toThrow(ValidationException::class);
    expect(User::withTrashed()->where('student_id', $this->student->student_id)->count())->toBe(1)
        ->and(User::withTrashed()->find($this->student->id)->trashed())->toBeTrue();
});

it('blocks ID entry, PIN sign-in and an existing evaluation session after deletion', function () {
    $pin = StudentPins::issue(collect([$this->student]))->first()['pin'];
    auth()->logout();
    $waitingLogin = Livewire::test(Login::class)->set('data.login', $this->student->student_id)
        ->call('authenticate')->assertSet('studentIdentifier', $this->student->student_id);
    $this->actingAs($this->actor);
    $this->deletion->delete($this->actor, $this->campus, [$this->student->id]);
    auth()->logout();
    $this->post(route('evaluation.identify'), ['student_id' => $this->student->student_id])->assertSessionHasErrors('student_id');
    Livewire::test(Login::class)->set('data.login', $this->student->student_id)->call('authenticate')->assertHasErrors('data.login');
    $waitingLogin->set('data.password', $pin)->call('authenticate')->assertHasErrors('data.login');
    expect(auth()->check())->toBeFalse();
    expect(auth()->guard()->getProvider()->retrieveById($this->student->id))->toBeNull();
    $this->withSession(['evaluation.student_id' => $this->student->id, 'evaluation.expires_at' => now()->addMinutes(10)->timestamp])
        ->get(route('evaluation.entry'))->assertOk()->assertViewHas('student', null);
    expect(fn () => app(EvaluationAccessService::class)->authorize($this->assignment, $this->student))->toThrow(AuthorizationException::class);
    expect($this->response->fresh()->data)->toBe($this->answers);
});

it('revokes pending approval requests and API tokens without removing history', function () {
    $this->student->createToken('old-student-session');
    DB::table('student_sign_in_requests')->insert(['user_id' => $this->student->id, 'token_hash' => hash('sha256', 'pending-browser'), 'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()]);
    $this->deletion->delete($this->actor, $this->campus, [$this->student->id]);
    expect($this->student->tokens()->count())->toBe(0)
        ->and(DB::table('student_sign_in_requests')->where('user_id', $this->student->id)->count())->toBe(0)
        ->and($this->response->fresh()->user->id)->toBe($this->student->id);
});

it('prevents permanent student deletion from cascading historical records', function () {
    expect(fn () => $this->student->forceDelete())->toThrow(ValidationException::class);
    expect(User::find($this->student->id))->not->toBeNull()
        ->and($this->response->fresh()->user_id)->toBe($this->student->id)
        ->and($this->student->fresh()->hasRole('student'))->toBeTrue();
});

it('retains deleted students historical roster links during later active evaluator changes', function () {
    foreach (['manage_evaluators_course::offering', 'update_course::offering'] as $permission) {
        $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $enrolledAt = DB::table('course_offering_student')->where('user_id', $this->student->id)->value('enrolled_at');
    $this->deletion->delete($this->actor, $this->campus, [$this->student->id]);
    $other = User::factory()->create(['is_student' => true, 'student_department_id' => $this->department->id]);
    $other->assignRole('student');
    $other->campuses()->attach($this->campus);
    app(AcademicStudents::class)->assign($this->actor, $this->assignment, [], [$other->id]);
    expect($this->assignment->students()->pluck('users.id')->all())->toBe([$other->id])
        ->and($this->assignment->historicalStudents()->count())->toBe(2)
        ->and(DB::table('course_offering_student')->where('user_id', $this->student->id)->value('enrolled_at'))->toBe($enrolledAt)
        ->and($this->response->fresh()->data)->toBe($this->answers);
    app(AcademicStudents::class)->assign($this->actor, $this->assignment, [], [], [], 'replace');
    expect($this->assignment->students()->count())->toBe(0)
        ->and(app(ReportService::class)->assignmentCompletion($this->assignment->fresh()))->toBe(['expected' => 1, 'completed' => 1, 'percentage' => 100.0]);
});
