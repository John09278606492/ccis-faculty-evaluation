<?php

use App\Models\FormSubmission;
use App\Models\Semester;
use App\Models\User;
use App\Services\AcademicStudentImports;
use App\Services\AcademicStudents;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    ['offering' => $this->offering, 'student' => $this->originalStudent] = evaluationFixture();
    $this->campus = $this->offering->campus;
    $this->actor = $this->offering->instructor;
    $this->actor->campuses()->attach($this->campus);
    foreach (['view_academic_students', 'manage_academic_students', 'import_academic_students', 'update_course::offering', 'manage_evaluators_course::offering'] as $permission) {
        $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($this->actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    $this->service = app(AcademicStudents::class);
    $this->data = ['student_id' => 'ROSTER-001', 'first_name' => 'Maria', 'last_name' => 'Cruz',
        'student_department_id' => $this->actor->instructor_department_id,
        'department_code' => $this->offering->course->department->code,
        'semester_id' => $this->offering->semester_id, 'sections' => ['BSIT 3A']];
});

it('blocks legacy privileged student targets in direct edits imports and import review', function (string $unsafeProfile) {
    $student = $this->service->save($this->actor, $this->campus, $this->data);
    // Keep a previously hydrated record to verify that save reads current access again.
    $student->load('roles.permissions', 'permissions');
    $changed = $student->fresh();
    match ($unsafeProfile) {
        'super_admin' => $changed->assignRole('super_admin'),
        'staff_permission' => $changed->givePermissionTo(Permission::findOrCreate('manage_academic_students', 'web')),
        'instructor' => $changed->update(['is_instructor' => true]),
    };
    expect(fn () => $this->service->save($this->actor, $this->campus, [...$this->data, 'first_name' => 'Overwritten'], $student))
        ->toThrow(ValidationException::class);
    expect(fn () => $this->service->import($this->actor, $this->campus, [
        [...$this->data, 'student_id' => 'ROLLBACK-002'],
        [...$this->data, 'first_name' => 'Overwritten'],
    ]))->toThrow(ValidationException::class);
    expect(User::where('student_id', 'ROLLBACK-002')->exists())->toBeFalse();
    expect($student->fresh()->first_name)->toBe('Maria');
    $review = app(AcademicStudentImports::class)->review($this->actor, $this->campus, [$this->data], $this->offering->semester_id);
    expect($review['error_count'])->toBe(1)->and($review['updated_students'])->toBe(0);
})->with(['super_admin', 'staff_permission', 'instructor']);

it('rechecks legacy role changes between import review and commit', function () {
    $student = $this->service->save($this->actor, $this->campus, $this->data);
    $imports = app(AcademicStudentImports::class);
    $review = $imports->review($this->actor, $this->campus, [[...$this->data, 'first_name' => 'Overwritten']], $this->offering->semester_id);
    $student->assignRole('super_admin');
    expect(fn () => $imports->commit($this->actor, $this->campus, $review['token'], $this->offering->semester_id))
        ->toThrow(ValidationException::class);
    expect($student->fresh()->first_name)->toBe('Maria');
});

it('imports a new period without inheriting or deleting earlier section memberships', function () {
    $student = $this->service->save($this->actor, $this->campus, $this->data);
    $next = Semester::create(['school_year_id' => $this->offering->semester->school_year_id, 'name' => 'Second Semester',
        'start_date' => '2027-01-01', 'end_date' => '2027-05-31', 'is_active' => false]);
    $this->service->import($this->actor, $this->campus, [[...$this->data, 'sections' => 'BSIT 3B']], $next->id);
    expect($this->service->sections($student, $this->offering->semester_id))->toBe(['BSIT 3A'])
        ->and($this->service->sections($student, $next->id))->toBe(['BSIT 3B']);
    $this->service->import($this->actor, $this->campus, [[...$this->data, 'sections' => 'BSIT 2C|BSIT 3B']], $next->id);
    expect($this->service->sections($student, $next->id))->toBe(['BSIT 3B', 'BSIT 2C']);
    $this->service->save($this->actor, $this->campus, [...$this->data, 'semester_id' => $next->id, 'sections' => ['BSIT 4A']], $student);
    expect($this->service->sections($student, $this->offering->semester_id))->toBe(['BSIT 3A'])
        ->and($this->service->sections($student, $next->id))->toBe(['BSIT 4A']);
    expect(array_values($this->service->sectionOptions($this->actor, $this->campus, $this->offering->semester_id)))->toBe(['CCIS · BSIT 3A']);
    // Saved section definitions remain available even after their last student moves.
    expect(array_values($this->service->sectionOptions($this->actor, $this->campus, $next->id)))->toBe(['CCIS · BSIT 2C', 'CCIS · BSIT 3B', 'CCIS · BSIT 4A']);
});

it('uses only the teaching class period when selecting section groups', function () {
    $student = $this->service->save($this->actor, $this->campus, $this->data);
    $next = Semester::create(['school_year_id' => $this->offering->semester->school_year_id, 'name' => 'Second Semester',
        'start_date' => '2027-01-01', 'end_date' => '2027-05-31', 'is_active' => false]);
    $this->service->save($this->actor, $this->campus, [...$this->data, 'semester_id' => $next->id, 'sections' => ['BSIT 3B']], $student);
    $oldGroups = array_keys($this->service->sectionOptions($this->actor, $this->campus, $this->offering->semester_id));
    $nextClass = $this->offering->replicate()->fill(['semester_id' => $next->id]);
    $nextClass->save();
    expect(fn () => $this->service->selection($this->actor, $nextClass, $oldGroups))
        ->toThrow(HttpException::class);
    $nextGroups = array_keys($this->service->sectionOptions($this->actor, $this->campus, $next->id));
    $this->service->assign($this->actor, $nextClass, $nextGroups);
    expect($nextClass->students()->pluck('users.id')->all())->toBe([$student->id]);
});

it('keeps existing students in add mode and replaces the roster without deleting submitted responses', function () {
    $student = $this->service->save($this->actor, $this->campus, $this->data);
    $extra = $this->service->save($this->actor, $this->campus, [...$this->data, 'student_id' => 'ROSTER-002', 'sections' => ['BSIT 2B']]);
    $submission = FormSubmission::create(['form_id' => $this->offering->form_id, 'course_offering_id' => $this->offering->id,
        'user_id' => $this->originalStudent->id, 'data' => ['teaching_quality' => 5]]);
    $originalData = $submission->fresh()->getRawOriginal();
    $group = $this->actor->instructor_department_id.'~'.$this->offering->semester_id.'~'.rawurlencode('BSIT 3A');
    $add = $this->service->selection($this->actor, $this->offering, [$group], [$student->id], [], 'add');
    expect($add)->toMatchArray(['added' => 1, 'removed' => 0, 'total' => 2]);
    $this->service->assign($this->actor, $this->offering, [$group], [$student->id], [], 'add');
    expect($this->offering->students()->whereKey($this->originalStudent)->exists())->toBeTrue();
    $replace = $this->service->selection($this->actor, $this->offering, [], [$extra->id], [], 'replace');
    expect($replace)->toMatchArray(['added' => 1, 'removed' => 2, 'total' => 1]);
    $this->service->assign($this->actor, $this->offering, [], [$extra->id], [], 'replace');
    expect($this->offering->students()->pluck('users.id')->all())->toBe([$extra->id]);
    expect($submission->fresh()->getRawOriginal())->toBe($originalData);
    expect($this->offering->fresh()->evaluator_selection)->toMatchArray(['mode' => 'replace', 'count' => 1]);
});
