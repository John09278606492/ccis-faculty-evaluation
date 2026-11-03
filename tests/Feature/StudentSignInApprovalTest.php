<?php

use App\Filament\Pages\Login;
use App\Filament\Resources\CourseOfferingResource\Pages\EditCourseOffering;
use App\Filament\Resources\CourseOfferingResource\RelationManagers\StudentsRelationManager;
use App\Filament\Resources\QuizResource\Pages\EditQuiz;
use App\Filament\Resources\QuizResource\RelationManagers\StudentsRelationManager as AssessmentStudents;
use App\Models\BackgroundTask;
use App\Models\Quiz;
use App\Models\User;
use App\Services\BackgroundTasks;
use App\Services\StudentPins;
use App\Services\StudentSignIns;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('signs in only the approved waiting browser and consumes approval once', function () {
    ['student' => $student] = evaluationFixture();
    $student->update(['student_id' => 'WAIT-100']);
    Livewire::test(Login::class)->set('data.login', 'WAIT-100')->call('authenticate')->assertRedirect();
    $token = session('student_sign_in_token');
    $this->postJson(route('student.sign-in.check'))->assertJson(['state' => 'pending']);
    expect(auth()->check())->toBeFalse();
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $this->actingAs($admin);
    expect(StudentSignIns::approve(collect([$student])))->toBe(1);
    auth()->logout();
    $this->withSession(['student_sign_in_token' => 'another-browser'])->postJson(route('student.sign-in.check'))->assertJson(['state' => 'expired']);
    expect(auth()->check())->toBeFalse();
    $this->withSession(['student_sign_in_token' => $token])->postJson(route('student.sign-in.check'))->assertJson(['state' => 'approved']);
    expect(auth()->id())->toBe($student->id);
    auth()->logout();
    $this->withSession(['student_sign_in_token' => $token])->postJson(route('student.sign-in.check'))->assertJson(['state' => 'expired']);
    expect(auth()->check())->toBeFalse();
});

it('does not expose bulk sign-in approvals on evaluations', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $classmates = User::factory()->count(2)->create(['is_student' => true]);
    foreach ($classmates as $classmate) {
        $classmate->assignRole('student');
        $offering->students()->attach($classmate);
        StudentSignIns::request($classmate);
        session()->forget('student_sign_in_token'); // Independent browser.
    }
    StudentSignIns::request($student);
    $instructor = $offering->instructor;
    foreach (['view_course::offering', 'update_course::offering'] as $permission) {
        $instructor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    Filament::setTenant($offering->semester->schoolYear->campus, isQuiet: true);
    $this->actingAs($instructor);
    Livewire::test(StudentsRelationManager::class, ['ownerRecord' => $offering, 'pageClass' => EditCourseOffering::class])
        ->assertTableBulkActionDoesNotExist('approveSignIns');
    expect(DB::table('student_sign_in_requests')->whereNotNull('approved_at')->count())->toBe(0)
        ->and(StudentSignIns::pendingCount($classmates[1]->id))->toBe(1);
});

it('rejects approvals from unrelated instructors and students', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    StudentSignIns::request($student);
    $other = User::factory()->create(['is_instructor' => true]);
    $other->givePermissionTo(Permission::findOrCreate('update_course::offering', 'web'));
    expect(StudentSignIns::canApprove($other, $student, $offering))->toBeFalse()
        ->and(StudentSignIns::canApprove($student, $student, $offering))->toBeFalse();
    $this->actingAs($other);
    StudentSignIns::approve(collect([$student]), $offering);
})->throws(HttpException::class);

it('does not approve future requests and rejects expired approvals', function () {
    ['student' => $student] = evaluationFixture();
    StudentSignIns::request($student);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $this->actingAs($admin);
    StudentSignIns::approve(collect([$student]));
    auth()->logout();
    $this->travel(16)->minutes();
    $this->postJson(route('student.sign-in.check'))->assertJson(['state' => 'expired']);
    StudentSignIns::request($student);
    $this->postJson(route('student.sign-in.check'))->assertJson(['state' => 'pending']);
    expect(auth()->check())->toBeFalse();
});

it('cancels approval when the student changes the selected ID', function () {
    ['student' => $student] = evaluationFixture();
    StudentSignIns::request($student);
    Livewire::test(Login::class)->call('changeStudentId');
    expect(DB::table('student_sign_in_requests')->count())->toBe(0);
    $this->postJson(route('student.sign-in.check'))->assertJson(['state' => 'expired']);
});

it('requires user management permission and campus membership for administrative approval', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $admin = User::factory()->create(['is_student' => false]);
    $admin->givePermissionTo(Permission::findOrCreate('update_user', 'web'));
    $campus = $offering->semester->schoolYear->campus;
    Filament::setTenant($campus, isQuiet: true);
    expect(StudentSignIns::canApprove($admin, $student))->toBeFalse();
    $admin->campuses()->attach($campus);
    DB::table('user_campus_access')->insert(['user_id' => $admin->id, 'campus_id' => $campus->id, 'scope' => 'campus', 'created_at' => now(), 'updated_at' => now()]);
    expect(StudentSignIns::canApprove($admin, $student))->toBeTrue();
    $admin->revokePermissionTo('update_user');
    expect(StudentSignIns::canApprove($admin, $student))->toBeFalse();
});

function assessmentSignInFixture(string $type): array
{
    $fixture = evaluationFixture();
    $instructor = $fixture['offering']->instructor;
    $instructor->campuses()->syncWithoutDetaching([$fixture['offering']->campus->id]);
    foreach (['view_course::offering', 'update_course::offering', 'view_quiz', 'update_quiz'] as $permission) {
        $instructor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    Filament::setTenant($fixture['offering']->campus, isQuiet: true);
    $quiz = Quiz::create(['course_offering_id' => $fixture['offering']->id, 'title' => 'Approval test',
        'type' => $type, 'assign_to_all' => true, 'auto_sync_class_record' => false]);

    return [...$fixture, 'quiz' => $quiz, 'instructor' => $instructor];
}

it('approves a waiting student directly into the chosen assessment without starting an attempt', function (string $type) {
    extract(assessmentSignInFixture($type));
    StudentSignIns::request($student);
    $this->actingAs($instructor);
    Livewire::test(AssessmentStudents::class, ['ownerRecord' => $quiz, 'pageClass' => EditQuiz::class])
        ->callTableAction('approveSignIn', $student)->assertHasNoTableActionErrors();
    auth()->logout();
    $this->postJson(route('student.sign-in.check'))->assertJson([
        'state' => 'approved', 'url' => '/'.$offering->campus->id.'/take-assessment/'.$quiz->id,
    ]);
    expect(auth()->id())->toBe($student->id)->and($quiz->attempts()->count())->toBe(0);
})->with(['quiz', 'exam', 'activity']);

it('bulk approves only selected assigned assessment students', function (string $type) {
    extract(assessmentSignInFixture($type));
    $others = User::factory()->count(2)->create(['is_student' => true]);
    foreach (collect([$student, ...$others]) as $person) {
        $offering->students()->syncWithoutDetaching([$person->id]);
        StudentSignIns::request($person);
        session()->forget('student_sign_in_token');
    }
    $quiz->update(['assign_to_all' => false]);
    $quiz->assignedStudents()->sync([$student->id, $others[1]->id]);
    $this->actingAs($instructor);
    Livewire::test(AssessmentStudents::class, ['ownerRecord' => $quiz, 'pageClass' => EditQuiz::class])
        ->callTableBulkAction('approveSignIns', collect([$student, $others[0]]), data: ['request_ids' => DB::table('student_sign_in_requests')->where('user_id', $student->id)->pluck('id')->all()])->assertHasNoTableBulkActionErrors();
    expect(DB::table('student_sign_in_requests')->whereNotNull('approved_at')->pluck('user_id')->all())->toBe([$student->id])
        ->and(StudentSignIns::pendingCount($others[0]->id))->toBe(1)
        ->and(StudentSignIns::pendingCount($others[1]->id))->toBe(1)
        ->and($quiz->fresh()->assign_to_all)->toBeFalse()
        ->and($quiz->attempts()->count())->toBe(0);
})->with(['quiz', 'exam', 'activity']);

it('generates only missing selected PINs from the assessment roster', function () {
    extract(assessmentSignInFixture('quiz'));
    $other = User::factory()->create(['is_student' => true]);
    $other->campuses()->attach($offering->campus);
    $offering->students()->attach($other);
    StudentPins::issue(collect([$other]));
    $existing = $other->fresh()->password;
    $this->actingAs($instructor);
    Livewire::test(AssessmentStudents::class, ['ownerRecord' => $quiz, 'pageClass' => EditQuiz::class])
        ->callTableBulkAction('issueMissingPins', collect([$student, $other]))->assertHasNoTableBulkActionErrors();
    expect($student->fresh()->pin_issued_at)->toBeNull();
    $task = BackgroundTask::where('kind', 'assessment-pins')->sole();
    app(BackgroundTasks::class)->run($task->id);
    expect($task->fresh()->status)->toBe('completed', $task->fresh()->error ?? '');
    expect($task->fresh()->result['encrypted'])->toBeTrue();
    $csv = Crypt::decryptString(Storage::disk('local')->get($task->fresh()->file_path));
    expect($csv)->toContain($student->student_id);
    $this->get(route('background.download', $task))->assertOk()->assertDownload('assessment-student-pins.csv');
    expect($student->fresh()->pin_issued_at)->not->toBeNull()->and($other->fresh()->password)->toBe($existing);
});

it('approves only the selected browser when two valid requests use one student ID', function () {
    extract(assessmentSignInFixture('exam'));
    StudentSignIns::request($student);
    $firstToken = session('student_sign_in_token');
    $first = DB::table('student_sign_in_requests')->where('user_id', $student->id)->first();
    session()->forget('student_sign_in_token');
    StudentSignIns::request($student);
    $second = DB::table('student_sign_in_requests')->where('user_id', $student->id)->where('id', '!=', $first->id)->first();
    $this->actingAs($instructor);
    expect(fn () => StudentSignIns::approve(collect([$student]), assessment: $quiz))->toThrow(ValidationException::class);
    expect(StudentSignIns::approve(collect([$student]), assessment: $quiz, requestIds: [$first->id]))->toBe(1);
    expect(DB::table('student_sign_in_requests')->where('id', $second->id)->value('approved_at'))->toBeNull();
    auth()->logout();
    $this->withSession(['student_sign_in_token' => $firstToken])->postJson(route('student.sign-in.check'))->assertJson(['state' => 'approved']);
});

it('rechecks assignment before consuming approval and does not need a tenant in the student browser', function () {
    extract(assessmentSignInFixture('exam'));
    StudentSignIns::request($student);
    $token = session('student_sign_in_token');
    $this->actingAs($instructor);
    StudentSignIns::approve(collect([$student]), assessment: $quiz);
    $offering->students()->detach($student);
    auth()->logout();
    Filament::setTenant(null, isQuiet: true);
    $this->withSession(['student_sign_in_token' => $token])->postJson(route('student.sign-in.check'))->assertJson(['state' => 'expired']);
});
