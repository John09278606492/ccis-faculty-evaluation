<?php

use App\Models\Campus;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Form;
use App\Models\FormField;
use App\Models\SchoolYear;
use App\Models\Semester;
use App\Models\User;
use Spatie\Permission\Models\Role;

function evaluationFixture(bool $enrolled = true): array
{
    Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    $role = Role::create(['name' => 'student', 'guard_name' => 'web']);
    $campus = Campus::create(['name' => 'Main Campus', 'slug' => 'main', 'is_active' => true]);
    $department = Department::create([
        'campus_id' => $campus->id,
        'name' => 'College of Computing',
        'code' => 'CCIS',
        'is_active' => true,
    ]);
    $schoolYear = SchoolYear::create([
        'campus_id' => $campus->id,
        'name' => '2026-2027',
        'start_date' => '2026-06-01',
        'end_date' => '2027-05-31',
        'is_current' => true,
    ]);
    $semester = Semester::create([
        'school_year_id' => $schoolYear->id,
        'name' => 'First Semester',
        'start_date' => '2026-06-01',
        'end_date' => '2026-12-31',
        'is_active' => true,
    ]);
    $course = Course::create([
        'department_id' => $department->id,
        'code' => 'CS101',
        'name' => 'Introduction to Computing',
        'is_active' => true,
    ]);
    $instructor = User::factory()->create([
        'is_instructor' => true,
        'instructor_department_id' => $department->id,
    ]);
    $form = Form::create([
        'name' => 'Faculty Evaluation',
        'slug' => 'faculty-evaluation',
        'is_active' => true,
        'requires_login' => true,
        'evaluator_roles' => ['student'],
        'rating_scale' => [
            ['value' => 5, 'label' => 'Excellent'],
            ['value' => 1, 'label' => 'Needs improvement'],
        ],
    ]);
    $form->campuses()->attach($campus);
    FormField::create([
        'form_id' => $form->id,
        'name' => 'teaching_quality',
        'label' => 'Teaching quality',
        'type' => 'likert_scale',
        'is_required' => true,
        'order' => 1,
    ]);
    $offering = CourseOffering::create([
        'semester_id' => $semester->id,
        'course_id' => $course->id,
        'user_id' => $instructor->id,
        'section' => 'A',
        'is_active' => true,
        'form_id' => $form->id,
    ]);
    $student = User::factory()->create(['is_student' => true]);
    $student->assignRole($role);
    $student->campuses()->attach($campus);

    if ($enrolled) {
        $offering->students()->attach($student, ['enrolled_at' => now()]);
    }

    return compact('student', 'offering', 'form');
}
