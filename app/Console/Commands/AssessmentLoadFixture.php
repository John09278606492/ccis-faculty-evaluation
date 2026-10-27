<?php

namespace App\Console\Commands;

use App\Models\Campus;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Quiz;
use App\Models\SchoolYear;
use App\Models\Semester;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * One 4th-year class, its instructor and a coding exam, for scripts/assessment-load-test.cjs.
 * Only runs against an empty SQLite database under storage/app/load-tests.
 */
class AssessmentLoadFixture extends Command
{
    protected $signature = 'classroom:assessment-load-fixture {--students=120} {--minutes=90} {--manifest=}';

    protected $description = 'Create an isolated 4th-year coding exam load-test fixture; never runs in the live environment';

    public function handle(): int
    {
        abort_unless(app()->environment('loadtesting') && DB::getDriverName() === 'sqlite'
            && str_contains(str_replace('\\', '/', DB::connection()->getDatabaseName()), '/storage/app/load-tests/')
            && User::count() === 0, 403, 'Use a new isolated load-testing database.');
        $count = max(1, min(1000, (int) $this->option('students')));
        foreach ([...RolesSeeder::INSTRUCTOR, ...RolesSeeder::STUDENT, 'view_any_quiz', 'view_quiz', 'update_quiz'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::findOrCreate('student', 'web')->syncPermissions(RolesSeeder::STUDENT);
        Role::findOrCreate('instructor', 'web')->syncPermissions([...RolesSeeder::INSTRUCTOR, 'view_any_quiz', 'view_quiz', 'update_quiz']);
        $campus = Campus::create(['name' => '[LOAD] Main Campus', 'slug' => 'load-main', 'is_active' => true]);
        $department = Department::create(['campus_id' => $campus->id, 'name' => 'College of Computing and Information Sciences', 'code' => 'CCIS', 'is_active' => true]);
        $year = SchoolYear::create(['campus_id' => $campus->id, 'name' => 'LOAD '.now()->year, 'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(6), 'is_current' => true]);
        $semester = Semester::create(['school_year_id' => $year->id, 'name' => 'Load Test Semester', 'start_date' => now()->subDay(), 'end_date' => now()->addMonths(3), 'is_active' => true]);
        $password = 'IsolatedLoadTest!2026';
        $instructor = User::create(['first_name' => 'Load', 'last_name' => 'Instructor', 'email' => 'instructor@load.invalid', 'password' => $password,
            'is_student' => false, 'is_instructor' => true, 'instructor_department_id' => $department->id, 'must_change_password' => false]);
        $instructor->campuses()->attach($campus);
        $instructor->assignRole('instructor');
        $course = Course::create(['department_id' => $department->id, 'code' => 'IT 411', 'name' => 'Web Systems and Technologies 2', 'is_active' => true]);
        $offering = CourseOffering::create(['semester_id' => $semester->id, 'course_id' => $course->id, 'user_id' => $instructor->id, 'section' => 'BSIT 4A', 'is_active' => true]);
        // Students sign in with an instructor-issued PIN, as in class. The hash is cheap only to keep setup fast.
        $pin = '482913';
        $hash = Hash::make($pin, ['rounds' => 4]);
        $students = [];
        for ($i = 1; $i <= $count; $i++) {
            $student = User::create(['first_name' => 'Fourth Year', 'last_name' => 'Student '.$i, 'email' => sprintf('student.%04d@load.invalid', $i),
                'student_id' => sprintf('LOAD-4-%04d', $i), 'year_level' => 4, 'student_department_id' => $department->id,
                'is_student' => true, 'is_instructor' => false, 'must_change_password' => false]);
            $student->forceFill(['password' => $hash, 'pin_issued_at' => now()])->saveQuietly();
            $student->campuses()->attach($campus);
            $student->assignRole('student');
            $students[] = ['id' => $student->id, 'student_id' => $student->student_id];
        }
        $offering->students()->attach(array_column($students, 'id'), ['enrolled_at' => now()]);
        $quiz = Quiz::create(['course_offering_id' => $offering->id, 'auto_sync_class_record' => false, 'title' => 'Midterm Practical Exam', 'type' => 'exam',
            'duration_minutes' => (int) $this->option('minutes'), 'max_attempts' => 1, 'is_published' => false, 'assign_to_all' => true]);
        $php = $quiz->questions()->create(['type' => 'code', 'question_text' => 'Complete the grade report: compute each student\'s average and remarks.', 'points' => 40, 'order' => 1, 'options' => [
            'language' => 'php', 'allow_student_files' => true, 'files' => [
                ['name' => 'index.php', 'content' => "<?php\nrequire 'functions.php';\nrequire 'data/students.php';\n\nforeach (\$students as \$student) {\n    // TODO\n}\n"],
                ['name' => 'functions.php', 'content' => "<?php\n\nfunction average(array \$grades): float\n{\n    // TODO\n}\n"],
                ['name' => 'data/students.php', 'content' => "<?php\n\$students = [\n    ['name' => 'Ana', 'grades' => [88, 92, 79]],\n    ['name' => 'Ben', 'grades' => [75, 81, 70]],\n];\n"],
            ]]]);
        $web = $quiz->questions()->create(['type' => 'code', 'question_text' => 'Build the enrolment form page with validation.', 'points' => 30, 'order' => 2, 'options' => [
            'language' => 'web', 'allow_student_files' => true, 'files' => [
                ['name' => 'index.html', 'content' => "<!doctype html>\n<html>\n<head><link rel=\"stylesheet\" href=\"style.css\"></head>\n<body>\n<form id=\"enrol\"></form>\n<script src=\"script.js\"></script>\n</body>\n</html>\n"],
                ['name' => 'style.css', 'content' => "body { font-family: sans-serif; }\n"],
                ['name' => 'script.js', 'content' => "document.getElementById('enrol').addEventListener('submit', e => {\n  // TODO\n});\n"],
            ]]]);
        $short = $quiz->questions()->create(['type' => 'short_answer', 'question_text' => 'Which PHP superglobal holds submitted form fields sent by POST?', 'points' => 5, 'order' => 3, 'correct_answer' => ['$_POST']]);
        $quiz->update(['is_published' => true]);
        $manifest = ['campus' => $campus->id, 'quiz' => $quiz->id, 'course' => $offering->id, 'pin' => $pin,
            'instructor' => ['email' => $instructor->email, 'password' => $password],
            'questions' => ['php' => $php->id, 'web' => $web->id, 'short' => $short->id],
            'starter' => ['php' => $php->starterProject(), 'web' => $web->starterProject()], 'students' => $students];
        file_put_contents($this->option('manifest'), json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->info("Fixture ready: {$count} 4th-year students, 1 instructor, coding exam #{$quiz->id}.");

        return self::SUCCESS;
    }
}
