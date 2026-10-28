<?php

namespace Database\Seeders;

use App\Models\Campus;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\SchoolYear;
use App\Models\Semester;
use App\Models\User;
use App\Services\EvaluationTemplates;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class BalilihanEvaluationSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $campus = Campus::firstOrCreate(['slug' => 'balilihan'], ['name' => 'Balilihan Campus', 'is_active' => true]);
            $year = SchoolYear::firstOrCreate(['campus_id' => $campus->id, 'name' => '2026-2027'], ['start_date' => '2026-06-01', 'end_date' => '2027-05-31', 'is_current' => true]);
            $semester = Semester::firstOrCreate(['school_year_id' => $year->id, 'name' => 'First Semester'], ['start_date' => '2026-06-01', 'end_date' => '2027-01-31', 'is_active' => true]);
            $reviewer = Role::findOrCreate('balilihan_sample_reviewer', 'web');
            foreach (['view_any_course::offering', 'view_course::offering', 'view_any_form', 'view_form', 'view_any_department', 'page_EvaluationResults', 'page_MyProfilePage'] as $permission) {
                $reviewer->givePermissionTo(Permission::findOrCreate($permission, 'web'));
            }
            foreach (User::role('super_admin')->get() as $admin) {
                $admin->campuses()->syncWithoutDetaching([$campus->id]);
            }
            $dean = $this->account('sample.dean@balilihan.test', 'Sample', 'Balilihan Dean', $campus);
            $dean->assignRole($reviewer);
            DB::table('user_campus_access')->updateOrInsert(
                ['user_id' => $dean->id, 'campus_id' => $campus->id],
                ['scope' => 'campus', 'created_at' => now(), 'updated_at' => now()],
            );

            foreach (['CCJ', 'CTAS', 'CCIS'] as $index => $code) {
                $department = Department::firstOrCreate(['campus_id' => $campus->id, 'code' => $code], ['name' => $code, 'is_active' => true]);
                if ($department->name === ucfirst(strtolower($code))) {
                    DB::table('departments')->where('id', $department->id)->update(['name' => $code]);
                    $department->refresh();
                }
                $forms = [];
                foreach (['A' => 'SET', 'B' => 'SEF'] as $annex => $type) {
                    $slug = 'balilihan-sample-'.strtolower($code.'-'.$type);
                    $form = Form::where('slug', $slug)->first();
                    if (! $form) {
                        $form = app(EvaluationTemplates::class)->create($annex, $campus);
                        $form->update(['slug' => $slug, 'name' => "[SAMPLE] {$code} — {$type}", 'is_active' => true, 'description' => '[SAMPLE DATA ONLY] '.$form->description, 'evaluator_roles' => $type === 'SET' ? ['student'] : [$reviewer->name]]);
                        $form->departments()->attach($department);
                    }
                    $forms[$type] = $form;
                }
                $faculty = $this->account('sample.faculty.'.strtolower($code).'@balilihan.test', 'Sample Faculty', $code, $campus, ['is_instructor' => true, 'instructor_department_id' => $department->id]);
                $faculty->assignRole(Role::findOrCreate('instructor', 'web'));
                $head = $this->account('sample.head.'.strtolower($code).'@balilihan.test', 'Sample Head', $code, $campus, ['is_instructor' => true, 'instructor_department_id' => $department->id]);
                $head->assignRole($reviewer);
                if (! $department->supervisor_id) {
                    $department->update(['supervisor_id' => $head->id]);
                }
                if (! $department->chairperson_id) {
                    $department->update(['chairperson_id' => $head->id]);
                }
                $students = collect();
                for ($number = 1; $number <= 10; $number++) {
                    $id = sprintf('BAL-%s-2026-%03d', $code, $number);
                    $student = $this->account('sample.'.strtolower($id).'@balilihan.test', 'Sample '.$code.' Student', sprintf('%02d', $number), $campus, ['is_student' => true, 'student_id' => $id, 'year_level' => 3]);
                    $student->assignRole(Role::findOrCreate('student', 'web'));
                    $students->push($student);
                }
                for ($courseIndex = 1; $courseIndex <= 2; $courseIndex++) {
                    $course = Course::firstOrCreate(['department_id' => $department->id, 'code' => $code.'-SAMPLE-'.$courseIndex], ['name' => '[SAMPLE] '.$code.' Course '.$courseIndex, 'units' => 3, 'is_active' => true]);
                    $assignment = CourseOffering::firstOrCreate(['semester_id' => $semester->id, 'course_id' => $course->id, 'user_id' => $faculty->id, 'section' => $code.' 3A'], ['form_id' => $forms['SET']->id, 'evaluation_type' => 'SET', 'is_active' => true]);
                    $assignment->students()->syncWithoutDetaching($students->mapWithKeys(fn ($student) => [$student->id => ['enrolled_at' => now()]])->all());
                    foreach ($students->take(8) as $studentIndex => $student) {
                        $this->response($assignment, $forms['SET'], $student, $department, $index, $studentIndex + $courseIndex, '[SAMPLE] '.$code.': clear lessons and constructive feedback.');
                    }
                    if ($courseIndex === 1) {
                        // Leadership is not an evaluator by itself; the sample selects its supervisor explicitly.
                        $assignment->supervisorEvaluators()->syncWithoutDetaching([$head->id]);
                        $supervisorAssignment = CourseOffering::firstOrCreate(['semester_id' => $semester->id, 'course_id' => $course->id, 'user_id' => $faculty->id, 'section' => $code.' SEF'], ['form_id' => $forms['SEF']->id, 'evaluation_type' => 'SEF', 'is_active' => true]);
                    }
                }
                DB::table('faculty_development_reports')->insertOrIgnore([
                    'campus_id' => $campus->id, 'user_id' => $faculty->id, 'semester_id' => $semester->id,
                    'details' => json_encode(['rank' => '[SAMPLE] Instructor', 'prepared_name' => 'Sample '.$code.' Staff', 'reviewed_name' => 'Sample Head '.$code, 'areas' => '[SAMPLE] '.$code.' feedback and classroom engagement', 'activities' => '[SAMPLE] Department mentoring', 'action_plan' => '[SAMPLE] Monthly peer observation and review']),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->command?->info("Balilihan Campus ({$campus->id}): CCJ, CTAS, CCIS; 30 sample students, 3 faculty, 3 heads, 9 assignments, 48 sample SET responses. Each sample head is explicitly selected as the Annex B evaluator; supervisor evaluations start pending.");
        });
    }

    private function account(string $email, string $first, string $last, Campus $campus, array $attributes = []): User
    {
        $user = User::firstOrCreate(['email' => $email], [...$attributes, 'first_name' => $first, 'last_name' => $last, 'password' => Hash::make('BalilihanDemo!2026')]);
        $user->campuses()->syncWithoutDetaching([$campus->id]);

        return $user;
    }

    private function response(CourseOffering $assignment, Form $form, User $evaluator, Department $department, int $departmentIndex, int $offset, string $comment): void
    {
        $data = [];
        foreach ($form->fields()->where('type', 'likert_scale')->get() as $index => $field) {
            $data[$field->name] = match ($departmentIndex) {
                0 => 4 + (($index + $offset) % 2), 1 => 3 + (($index + $offset) % 3), default => 4 + (int) (($index + $offset) % 4 !== 0)
            };
        }
        $data['_overall_comment'] = $comment;
        FormSubmission::firstOrCreate(['submission_fingerprint' => hash_hmac('sha256', "evaluation:{$assignment->id}:user:{$evaluator->id}", config('evaluations.fingerprint_key'))], ['course_offering_id' => $assignment->id, 'form_id' => $form->id, 'department_id' => $department->id, 'user_id' => null, 'data' => $data, 'submitted_at' => now()]);
    }
}
