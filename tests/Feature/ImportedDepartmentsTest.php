<?php

use App\Models\Campus;
use App\Models\Course;
use App\Models\Department;
use App\Models\StudentSubjectEnrolment;
use App\Models\User;
use App\Services\AcademicStudentImports;
use App\Services\AcademicStudents;
use App\Services\ImportedDepartments;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    ['offering' => $this->offering] = evaluationFixture();
    $this->campus = $this->offering->campus;
    $this->actor = User::factory()->create(['is_student' => false]);
    $this->actor->assignRole('super_admin');
    foreach (['view_academic_students', 'manage_academic_students', 'import_academic_students', 'create_department', 'create_course'] as $permission) {
        $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($this->actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    $this->imports = app(AcademicStudentImports::class);
    $this->row = ['student_id' => 'DEPT-001', 'first_name' => 'Ana', 'last_name' => 'Sample', 'department_code' => 'CCJ',
        'subjects' => [['subject_code' => 'NEW101', 'description' => 'New Course', 'units' => 3]]];
});

it('previews then atomically creates distinct departments and their courses without duplicate departments on repeat imports', function () {
    $second = [...$this->row, 'student_id' => 'DEPT-002', 'department_code' => 'CTAS'];
    $third = [...$this->row, 'student_id' => 'DEPT-003', 'department_code' => ' ccj '];
    $rows = [$this->row, $second, $third];
    $review = $this->imports->review($this->actor, $this->campus, $rows, $this->offering->semester_id);
    expect($review)->toMatchArray(['new_departments' => 2, 'new_courses' => 2, 'error_count' => 0, 'new_students' => 3]);
    expect(Department::count())->toBe(1)->and(Course::count())->toBe(1);
    expect(view('filament.forms.academic-student-import-review', ['review' => $review, 'period' => 'Current period'])->render())
        ->toContain('Colleges / departments to add in this campus')->toContain('CCJ')->toContain('CTAS');
    expect($this->imports->commit($this->actor, $this->campus, $review['token'], $this->offering->semester_id))->toBe(3);
    expect(Department::count())->toBe(3)->and(Course::count())->toBe(3)->and(StudentSubjectEnrolment::count())->toBe(3);
    $student = User::where('student_id', 'DEPT-003')->sole();
    expect($student->student_department_id)->toBe(Department::where('code', 'CCJ')->sole()->id);
    $again = $this->imports->review($this->actor, $this->campus, $rows, $this->offering->semester_id);
    expect($again)->toMatchArray(['new_departments' => 0, 'new_courses' => 0, 'existing_courses' => 2]);
    $this->imports->commit($this->actor, $this->campus, $again['token'], $this->offering->semester_id);
    expect(Department::count())->toBe(3)->and(Course::count())->toBe(3);
});

it('reads optional department names from CSV and supports a campus with no departments yet', function () {
    $campus = Campus::create(['name' => 'New campus', 'slug' => 'new-import-campus', 'is_active' => true]);
    $year = $this->offering->semester->schoolYear->replicate();
    $year->campus_id = $campus->id;
    $year->save();
    $period = $this->offering->semester->replicate();
    $period->school_year_id = $year->id;
    $period->save();
    $file = UploadedFile::fake()->createWithContent('departments.csv', "student_id,first_name,last_name,College,College Name\nNEW-001,Ana,Sample,CCJ,College of Criminal Justice\n");
    $review = $this->imports->reviewUpload($this->actor, $campus, $file, $period->id);
    expect($review)->toMatchArray(['new_departments' => 1, 'error_count' => 0]);
    $this->imports->commit($this->actor, $campus, $review['token'], $period->id);
    $department = Department::where('campus_id', $campus->id)->sole();
    expect($department->code)->toBe('CCJ')->and($department->name)->toBe('College Of Criminal Justice')
        ->and($department->supervisor_id)->toBeNull()->and($department->chairperson_id)->toBeNull();
});

it('matches normalized existing codes and never overwrites department names or heads', function () {
    $department = $this->offering->course->department;
    $before = $department->getRawOriginal();
    $row = [...$this->row, 'department_code' => ' c c i s ', 'department_name' => 'A different supplied name'];
    $review = $this->imports->review($this->actor, $this->campus, [$row], $this->offering->semester_id);
    expect($review)->toMatchArray(['new_departments' => 0, 'existing_departments' => 1, 'error_count' => 0]);
    $this->imports->commit($this->actor, $this->campus, $review['token'], $this->offering->semester_id);
    expect($department->fresh()->getRawOriginal())->toBe($before);
});

it('requires both creation permission and campus-wide scope for new departments', function () {
    $this->actor->revokePermissionTo('create_department');
    $review = $this->imports->review($this->actor, $this->campus, [$this->row], $this->offering->semester_id);
    expect($review['error_count'])->toBe(1)->and($review['new_departments'])->toBe(0);
    $actor = $this->offering->instructor;
    $actor->campuses()->attach($this->campus);
    foreach (['view_academic_students', 'manage_academic_students', 'import_academic_students', 'create_department'] as $permission) {
        $actor->givePermissionTo($permission);
    }
    $review = $this->imports->review($actor, $this->campus, [$this->row], $this->offering->semester_id);
    expect($review['error_count'])->toBe(1)->and($review['new_departments'])->toBe(0);
    expect(Department::count())->toBe(1);
});

it('allows a campus administrator with explicit campus scope to create departments without a super-admin role', function () {
    $this->actor->removeRole('super_admin');
    $this->actor->campuses()->attach($this->campus);
    DB::table('user_campus_access')->insert(['user_id' => $this->actor->id, 'campus_id' => $this->campus->id, 'scope' => 'campus']);
    $review = $this->imports->review($this->actor, $this->campus, [$this->row], $this->offering->semester_id);
    expect($review['error_count'])->toBe(0)->and($review['new_departments'])->toBe(1);
    $this->imports->commit($this->actor, $this->campus, $review['token'], $this->offering->semester_id);
    expect(Department::where('code', 'CCJ')->count())->toBe(1);
});

it('does not adopt a department from another campus or reactivate an inactive department', function () {
    $foreign = Campus::create(['name' => 'Foreign', 'slug' => 'foreign-dept', 'is_active' => true]);
    Department::create(['campus_id' => $foreign->id, 'code' => 'CCJ', 'name' => 'Foreign College', 'is_active' => true]);
    $review = $this->imports->review($this->actor, $this->campus, [$this->row], $this->offering->semester_id);
    expect($review['new_departments'])->toBe(1);
    $this->imports->commit($this->actor, $this->campus, $review['token'], $this->offering->semester_id);
    expect(Department::where('code', 'CCJ')->count())->toBe(2);
    Department::where('campus_id', $this->campus->id)->where('code', 'CCJ')->update(['is_active' => false]);
    $review = $this->imports->review($this->actor, $this->campus, [$this->row], $this->offering->semester_id);
    expect($review['error_count'])->toBe(1)->and($review['new_departments'])->toBe(0);
});

it('blocks invalid codes and conflicting new names before any data is saved', function () {
    foreach (['', str_repeat('X', 21), '<script>'] as $code) {
        $review = $this->imports->review($this->actor, $this->campus, [[...$this->row, 'department_code' => $code]], $this->offering->semester_id);
        expect($review['error_count'])->toBe(1);
    }
    $rows = [[...$this->row, 'department_name' => 'College One'], [...$this->row, 'student_id' => 'DEPT-002', 'department_name' => 'College Two']];
    $review = $this->imports->review($this->actor, $this->campus, $rows, $this->offering->semester_id);
    expect($review['error_count'])->toBe(2)->and($review['new_departments'])->toBe(0);
    expect(fn () => $this->imports->commit($this->actor, $this->campus, $review['token'], $this->offering->semester_id))->toThrow(ValidationException::class);
    expect(Department::count())->toBe(1);
});

it('requires a fresh review when catalogue records or creation permission change after preview', function () {
    $review = $this->imports->review($this->actor, $this->campus, [$this->row], $this->offering->semester_id);
    Department::create(['campus_id' => $this->campus->id, 'code' => 'CCJ', 'name' => 'Already created', 'is_active' => true]);
    expect(fn () => $this->imports->commit($this->actor, $this->campus, $review['token'], $this->offering->semester_id))->toThrow(ValidationException::class);
    $review = $this->imports->review($this->actor, $this->campus, [[...$this->row, 'department_code' => 'CTAS']], $this->offering->semester_id);
    $this->actor->revokePermissionTo('create_department');
    expect(fn () => $this->imports->commit($this->actor, $this->campus, $review['token'], $this->offering->semester_id))->toThrow(ValidationException::class);
    expect(User::where('student_id', $this->row['student_id'])->exists())->toBeFalse();
});

it('rolls back new departments and courses when a later student row fails validation in direct imports', function () {
    $rows = [$this->row, [...$this->row, 'student_id' => 'DEPT-002', 'first_name' => '']];
    expect(fn () => app(AcademicStudents::class)->import($this->actor, $this->campus, $rows, $this->offering->semester_id))->toThrow(ValidationException::class);
    expect(Department::count())->toBe(1)->and(Course::count())->toBe(1)->and(User::where('student_id', 'DEPT-001')->exists())->toBeFalse();
});

it('flags equivalent existing department duplicates instead of choosing an arbitrary record', function () {
    DB::table('departments')->insert(['campus_id' => $this->campus->id, 'code' => 'c c i s', 'name' => 'Legacy Duplicate', 'is_active' => true]);
    $review = $this->imports->review($this->actor, $this->campus, [[...$this->row, 'department_code' => 'CCIS']], $this->offering->semester_id);
    expect($review['error_count'])->toBe(1)->and($review['new_departments'])->toBe(0);
    expect(implode(' ', $review['errors'][0]['messages']))->toContain('equivalent codes');
});

it('preserves explicit existing-department selections while reviewing new departments from the file', function () {
    Department::create(['campus_id' => $this->campus->id, 'code' => 'CTAS', 'name' => 'CTAS', 'is_active' => true]);
    $rows = [$this->row, [...$this->row, 'student_id' => 'DEPT-002', 'department_code' => 'CTAS'], [...$this->row, 'student_id' => 'DEPT-003', 'department_code' => 'CCIS']];
    $review = $this->imports->review($this->actor, $this->campus, $rows, $this->offering->semester_id, ['department_codes' => ['CCIS']]);
    expect($review)->toMatchArray(['total_rows' => 2, 'new_departments' => 1, 'skipped_department_rows' => 1, 'error_count' => 0]);
    $this->imports->commit($this->actor, $this->campus, $review['token'], $this->offering->semester_id);
    expect(User::where('student_id', 'DEPT-002')->exists())->toBeFalse();
});

it('keeps previously configured campus-wide permissions from becoming access to another campus', function () {
    $this->actor->removeRole('super_admin');
    $this->actor->campuses()->attach($this->campus);
    DB::table('user_campus_access')->insert(['user_id' => $this->actor->id, 'campus_id' => $this->campus->id, 'scope' => 'campus']);
    $foreign = Campus::create(['name' => 'Not assigned', 'slug' => 'not-assigned-import', 'is_active' => true]);
    expect(app(ImportedDepartments::class)->canCreate($this->actor, $foreign))->toBeFalse();
    expect(DB::table('user_department_access')->where('user_id', $this->actor->id)->count())->toBe(0);
});
