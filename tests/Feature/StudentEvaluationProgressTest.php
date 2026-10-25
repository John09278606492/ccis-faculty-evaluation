<?php

use App\Filament\Pages\StudentEvaluationProgress;
use App\Models\Campus;
use App\Models\Department;
use App\Models\EvaluationCycle;
use App\Models\FormSubmission;
use App\Models\User;
use App\Services\EvaluationSubmissionIdentity;
use App\Services\StudentEvaluationProgress as Progress;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('counts current assigned evaluations once and excludes inactive assignments and other semesters', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $second = $offering->replicate();
    $second->section = 'B';
    $second->save();
    $second->students()->attach($student);
    FormSubmission::create(['form_id' => $offering->form_id, 'course_offering_id' => $offering->id,
        'submission_fingerprint' => app(EvaluationSubmissionIdentity::class)->fingerprint($offering, request(), $student), 'data' => ['teaching_quality' => 5]]);
    expect(app(Progress::class)->counts($student, $offering->campus))->toBe(['completed' => 1, 'total' => 2, 'remaining' => 1]);
    $second->update(['is_active' => false]);
    expect(app(Progress::class)->counts($student, $offering->campus))->toBe(['completed' => 1, 'total' => 1, 'remaining' => 0]);
    expect(app(Progress::class)->counts($student, $offering->campus, 99999)['total'])->toBe(0);
});

it('requires the progress permission and renders counts without response contents', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $staff = User::factory()->create(['is_student' => false]);
    $staff->campuses()->attach($offering->campus);
    $this->actingAs($staff);
    Filament::setTenant($offering->campus);
    expect(StudentEvaluationProgress::canAccess())->toBeFalse();
    $staff->assignRole(Role::findOrCreate('chairperson', 'web'));
    $offering->course->department->update(['chairperson_id' => $staff->id]);
    $staff->givePermissionTo(Permission::findOrCreate('page_StudentEvaluationProgress', 'web'));
    expect(StudentEvaluationProgress::canAccess())->toBeTrue();
    Livewire::test(StudentEvaluationProgress::class)->assertSuccessful()->assertCanSeeTableRecords([$student])->assertSee('0 out of 1')->assertSee('Needs follow-up');
    Livewire::test(StudentEvaluationProgress::class)
        ->filterTable('department', 999999)
        ->assertCanNotSeeTableRecords([$student])
        ->filterTable('department', $offering->course->department_id)
        ->assertCanSeeTableRecords([$student])
        ->assertSee('0 out of 1');
    $this->actingAs($student);
    $student->givePermissionTo('page_StudentEvaluationProgress');
    expect(StudentEvaluationProgress::canAccess())->toBeFalse();
});

it('limits heads to assigned departments and keeps administrators within the selected campus', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $campus = $offering->campus;
    $head = User::factory()->create(['is_student' => false]);
    $head->campuses()->attach($campus);
    $head->assignRole(Role::findOrCreate('chairperson', 'web'));
    $offering->course->department->update(['chairperson_id' => $head->id]);
    $service = app(Progress::class);
    $ids = $service->departmentIds($head, $campus);
    expect($ids)->toBe([$offering->course->department_id]);
    expect($service->counts($student, $campus, null, $ids)['total'])->toBe(1);
    expect($service->counts($student, $campus, null, [])['total'])->toBe(0);
    $otherCampus = Campus::create(['name' => 'Other Campus', 'slug' => 'other-campus', 'is_active' => true]);
    expect($service->departmentIds($head, $otherCampus))->toBe([]);
    foreach (['view_any_user', 'update_user'] as $permission) {
        $head->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    expect($service->departmentIds($head, $campus))->toBe($ids);
    expect($service->departmentIds($head, $otherCampus))->toBe([]);
});

it('matches submissions to the real class id when enrollment pivot ids differ', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture(false);
    $unrelated = $offering->replicate();
    $unrelated->section = 'Unrelated';
    $unrelated->save();
    $otherStudent = User::factory()->create(['is_student' => true]);
    $unrelated->students()->attach($otherStudent);
    $offering->students()->attach($student);
    expect(DB::table('course_offering_student')->where('user_id', $student->id)->value('id'))->not->toBe($offering->id);
    FormSubmission::create(['form_id' => $offering->form_id, 'course_offering_id' => $offering->id,
        'submission_fingerprint' => app(EvaluationSubmissionIdentity::class)->fingerprint($offering, request(), $student), 'data' => ['teaching_quality' => 5]]);
    expect(app(Progress::class)->summary($student, $offering->campus))->toMatchArray([
        'completed' => 1, 'total' => 1, 'remaining' => 0, 'open_pending' => 0,
    ]);
});

it('distinguishes open future closed and exhausted assignments without counting them as zero ratings', function () {
    ['student' => $student, 'offering' => $offering, 'form' => $form] = evaluationFixture();
    $campus = $offering->campus;
    $service = app(Progress::class);
    expect($service->summary($student, $campus))->toMatchArray(['open_pending' => 1, 'not_open' => 0, 'closed_pending' => 0, 'unavailable' => 0]);
    $form->update(['starts_at' => now()->addDay()]);
    expect($service->summary($student, $campus))->toMatchArray(['not_open' => 1, 'open_pending' => 0, 'remaining' => 1]);
    $form->update(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
    expect($service->summary($student, $campus))->toMatchArray(['closed_pending' => 1, 'open_pending' => 0, 'remaining' => 1]);
    $form->update(['ends_at' => now()->addDay(), 'max_submissions' => 1]);
    FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $offering->id, 'data' => ['teaching_quality' => 4]]);
    expect($service->summary($student, $campus))->toMatchArray(['unavailable' => 1, 'open_pending' => 0, 'remaining' => 1]);
    $form->update(['max_submissions' => null]);
    $cycle = EvaluationCycle::create(['campus_id' => $campus->id, 'department_id' => $offering->course->department_id,
        'semester_id' => $offering->semester_id, 'name' => 'Scheduled cycle', 'status' => 'draft',
        'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);
    $offering->update(['evaluation_cycle_id' => $cycle->id]);
    expect($service->summary($student, $campus))->toMatchArray(['not_open' => 1, 'open_pending' => 0]);
    $cycle->update(['status' => 'published']);
    expect($service->summary($student, $campus))->toMatchArray(['not_open' => 0, 'open_pending' => 1]);
    $cycle->update(['status' => 'closed']);
    expect($service->summary($student, $campus))->toMatchArray(['closed_pending' => 1, 'open_pending' => 0]);
    $cycle->update(['status' => 'archived']);
    expect($service->summary($student, $campus))->toMatchArray(['closed_pending' => 1, 'remaining' => 1]);
});

it('uses an explicitly assigned teaching department before a faculty home department', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $homeId = $offering->course->department_id;
    $host = Department::create(['campus_id' => $offering->campus->id, 'name' => 'CTAS', 'code' => 'CTAS', 'is_active' => true]);
    $offering->update(['course_id' => null, 'teaching_department_id' => $host->id]);
    expect(app(Progress::class)->counts($student, $offering->campus, null, [$homeId])['total'])->toBe(0);
    expect(app(Progress::class)->counts($student, $offering->campus, null, [$host->id])['total'])->toBe(1);
});
