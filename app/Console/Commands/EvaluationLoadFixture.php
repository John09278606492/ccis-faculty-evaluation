<?php

namespace App\Console\Commands;

use App\Models\Campus;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\SchoolYear;
use App\Models\Semester;
use App\Models\User;
use App\Services\AcademicStudents;
use App\Services\EvaluationCycles;
use App\Services\EvaluationTemplates;
use App\Services\TeachingClasses;
use App\Services\UserAccessAdministration;
use Database\Seeders\RolesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class EvaluationLoadFixture extends Command
{
    protected $signature = 'classroom:evaluation-load-fixture {--students=1000} {--manifest=}';

    protected $description = 'Create an isolated SQLite role-workflow and student load-test fixture; never runs in the live environment';

    public function handle(): int
    {
        abort_unless(app()->environment('loadtesting') && DB::getDriverName() === 'sqlite'
            && str_contains(str_replace('\\', '/', DB::connection()->getDatabaseName()), '/storage/app/load-tests/')
            && User::count() === 0, 403, 'Use a new isolated load-testing database.');
        $count = max(1, min(5000, (int) $this->option('students')));
        $permissions = array_unique([...RolesSeeder::INSTRUCTOR, ...RolesSeeder::STUDENT,
            'view_any_campus', 'view_campus', 'view_any_department', 'view_department', 'create_department', 'update_department',
            'view_any_user', 'view_user', 'create_user', 'update_user', 'assign_user_roles', 'manage_access_assignments',
            'view_academic_students', 'manage_academic_students', 'import_academic_students', 'create_course::offering',
            'manage_evaluators_course::offering', 'page_EvaluationResults', 'page_StudentEvaluationProgress', 'view_any_form', 'view_form', 'update_form']);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $superRole = Role::findOrCreate('super_admin', 'web');
        $superRole->syncPermissions($permissions);
        Role::findOrCreate('student', 'web')->syncPermissions(RolesSeeder::STUDENT);
        Role::findOrCreate('instructor', 'web')->syncPermissions(RolesSeeder::INSTRUCTOR);
        $campus = Campus::create(['name' => '[LOAD] Balilihan', 'slug' => 'load-balilihan', 'is_active' => true]);
        $foreign = Campus::create(['name' => '[LOAD] Boundary Campus', 'slug' => 'load-boundary', 'is_active' => true]);
        $year = SchoolYear::create(['campus_id' => $campus->id, 'name' => 'LOAD '.now()->year, 'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(6), 'is_current' => true]);
        $semester = Semester::create(['school_year_id' => $year->id, 'name' => 'Load Test Semester', 'start_date' => now()->subDay(), 'end_date' => now()->addMonths(3), 'is_active' => true]);
        $password = 'IsolatedLoadTest!2026';
        $account = function (string $label, ?Department $department = null, bool $faculty = false) use ($campus, $password) {
            $user = User::create(['first_name' => 'Load', 'last_name' => $label, 'email' => strtolower(str_replace(' ', '.', $label)).'@load.invalid',
                'password' => $password, 'is_student' => false, 'is_instructor' => $faculty, 'instructor_department_id' => $department?->id, 'must_change_password' => false]);
            $user->campuses()->attach($campus);

            return $user;
        };
        $root = $account('Super Admin');
        $root->assignRole($superRole);
        $root->campuses()->attach($foreign);
        $adminRole = Role::create(['name' => 'load_campus_admin', 'guard_name' => 'web', 'team_id' => $campus->id]);
        $adminRole->syncPermissions($permissions);
        $headRole = Role::create(['name' => 'load_department_head', 'guard_name' => 'web', 'team_id' => $campus->id]);
        $headRole->syncPermissions(array_diff($permissions, ['manage_access_assignments', 'assign_user_roles', 'create_user', 'update_user', 'create_department']));
        $admin = $account('Campus Admin');
        $access = app(UserAccessAdministration::class);
        $access->assignRoles($root, $admin, $campus, [$adminRole->id]);
        $access->setScope($root, $admin, $campus, 'campus', []);
        auth()->login($admin);
        $staff = [['role' => 'super_admin', 'id' => $root->id, 'email' => $root->email], ['role' => 'campus_admin', 'id' => $admin->id, 'email' => $admin->email]];
        $departments = [];
        $cycles = [];
        $students = [];
        $workflow = [];
        foreach (['CCJ', 'CTAS', 'CCIS'] as $index => $code) {
            $department = Department::create(['campus_id' => $campus->id, 'name' => $code, 'code' => $code, 'is_active' => true]);
            $dean = $account($code.' Dean', $department);
            $chair = $account($code.' Chairperson', $department);
            foreach ([$dean, $chair] as $head) {
                $access->assignRoles($admin, $head, $campus, [$headRole->id]);
                $access->setScope($admin, $head, $campus, 'departments', [$department->id]);
            }
            $department->update(['supervisor_id' => $dean->id, 'chairperson_id' => $chair->id]);
            $staff[] = ['role' => 'dean', 'id' => $dean->id, 'email' => $dean->email, 'department' => $department->id];
            $staff[] = ['role' => 'chairperson', 'id' => $chair->id, 'email' => $chair->email, 'department' => $department->id];
            $number = intdiv($count, 3) + ($index < $count % 3 ? 1 : 0);
            $rows = [];
            for ($i = 1; $i <= $number; $i++) {
                $rows[] = ['student_id' => sprintf('LOAD-%s-%04d', $code, $i), 'first_name' => 'Load '.$code, 'last_name' => 'Student '.$i, 'department_code' => $code, 'sections' => $i % 10 === 0 ? [$code.' 1A', $code.' 1B'] : [$code.($i % 2 ? ' 1A' : ' 1B')]];
            }
            $this->line("Importing {$number} students in {$code} through AcademicStudents...");
            app(AcademicStudents::class)->import($admin, $campus, $rows, $semester->id);
            $forms = [];
            foreach (['A', 'B'] as $annex) {
                $forms[$annex] = app(EvaluationTemplates::class)->create($annex, $campus);
                $forms[$annex]->departments()->attach($department);
            }
            $groups = array_keys(app(AcademicStudents::class)->sectionOptions($dean, $campus, $semester->id));
            $classes = [];
            for ($i = 1; $i <= 2; $i++) {
                $faculty = $account($code.' Faculty '.$i, $department, true);
                $faculty->assignRole('instructor');
                $course = Course::create(['department_id' => $department->id, 'code' => 'LOAD-'.$code.'-'.$i, 'name' => 'Load Teaching Course '.$i, 'is_active' => true]);
                $class = app(TeachingClasses::class)->save($dean, $campus, ['semester_id' => $semester->id, 'course_id' => $course->id, 'user_id' => $faculty->id, 'section' => $code.' Combined', 'is_active' => true]);
                app(AcademicStudents::class)->assign($dean, $class, $groups);
                app(\App\Services\FacultySupervisorEvaluators::class)->save($dean, $class, [$dean->id, $chair->id]);
                $classes[] = $class;
                $staff[] = ['role' => 'instructor', 'id' => $faculty->id, 'email' => $faculty->email, 'department' => $department->id];
            }
            $cycle = app(EvaluationCycles::class)->save($dean, $campus, ['name' => '[LOAD] '.$code.' Cycle', 'department_id' => $department->id, 'semester_id' => $semester->id,
                'student_form_id' => $forms['A']->id, 'supervisor_form_id' => $forms['B']->id, 'assignment_ids' => array_map(fn ($c) => $c->id, $classes), 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(2)]);
            app(EvaluationCycles::class)->publish($dean, $cycle);
            $cycles[] = $cycle->id;
            $departments[] = $department->id;
            foreach (User::where('student_department_id', $department->id)->get() as $student) {
                $students[] = ['id' => $student->id, 'student_id' => $student->student_id, 'department' => $department->id,
                    'assignments' => array_map(fn ($c) => $c->id, $classes)];
            }
            $workflow[] = ['department' => $code, 'students' => $number, 'cycle' => $cycle->id, 'published' => $cycle->fresh()->status, 'roster_per_class' => $classes[0]->students()->count()];
        }
        $manifest = ['campus' => $campus->id, 'foreign_campus' => $foreign->id, 'period' => $semester->id, 'departments' => $departments, 'cycles' => $cycles,
            'staff_password' => $password, 'staff' => $staff, 'students' => $students, 'expected_student_submissions' => count($students) * 2,
            'supervisor_assignments' => CourseOffering::where('evaluation_type', 'SEF')->pluck('id')->all(), 'workflow' => $workflow];
        file_put_contents($this->option('manifest'), json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        auth()->logout();
        $this->info('Fixture ready: '.count($students).' students, '.count($staff).' staff, '.count($cycles).' published cycles.');

        return self::SUCCESS;
    }
}
