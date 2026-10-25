<?php

use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\StudentAcademicRecord;
use App\Services\AcademicSections;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('creates a class for a section with its students for the instructor, and reuses it', function () {
    ['student' => $student, 'offering' => $existing] = evaluationFixture(false);
    $instructor = $existing->instructor;
    $campus = $existing->campus;
    $department = $existing->course->department;
    $instructor->campuses()->syncWithoutDetaching($campus);
    $instructor->update(['is_instructor' => true]);
    $role = Role::findOrCreate('class_admin', 'web');
    foreach (['view_academic_students', 'manage_academic_students', 'manage_evaluators_course::offering', 'create_course::offering', 'update_course::offering', 'view_course::offering'] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $instructor->assignRole($role);
    $student->update(['student_id' => '000123', 'student_department_id' => $department->id]);
    StudentAcademicRecord::create(['user_id' => $student->id, 'semester_id' => $existing->semester_id, 'program' => 'BSIT', 'reported_sex' => 'M']);
    $this->actingAs($instructor);
    $section = app(AcademicSections::class)->save($instructor, $campus, ['name' => 'BSIT 3C', 'department_id' => $department->id, 'semester_id' => $existing->semester_id, 'is_active' => true]);
    app(AcademicSections::class)->add($instructor, $campus, $section, [$student->id]);
    auth()->logout();

    $run = fn () => $this->artisan('classroom:create-section-class', ['--section' => 'BSIT 3C', '--course-code' => 'PROF ELEC 2', '--course-name' => 'Professional Elective 2', '--instructor' => $instructor->id]);
    $run()->expectsOutputToContain('1 students.')->assertSuccessful();
    $run()->assertSuccessful();

    $class = CourseOffering::withoutGlobalScopes()->where('section', 'BSIT 3C')->sole();
    expect($class->user_id)->toBe($instructor->id)
        ->and($class->course->code)->toBe('PROF ELEC 2')
        ->and($class->students()->pluck('users.id')->all())->toBe([$student->id])
        ->and(Course::withoutGlobalScopes()->where('code', 'PROF ELEC 2')->count())->toBe(1);
});
