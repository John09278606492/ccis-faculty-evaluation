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
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DemoEvaluationSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $campus = Campus::firstOrCreate(['slug' => 'demo-presentation'], ['name' => 'DEMO Presentation Campus', 'is_active' => true]);
            $year = SchoolYear::firstOrCreate(['campus_id' => $campus->id, 'name' => 'DEMO 2026-2027'], ['start_date' => '2026-06-01', 'end_date' => '2027-05-31', 'is_current' => true]);
            $semester = Semester::firstOrCreate(['school_year_id' => $year->id, 'name' => 'DEMO First Semester'], ['start_date' => '2026-06-01', 'end_date' => '2027-01-31', 'is_active' => true]);
            $departments = collect(['Computing Studies', 'General Education'])->map(fn ($name, $index) => Department::firstOrCreate(['campus_id' => $campus->id, 'code' => 'DEMO-'.($index + 1)], ['name' => 'DEMO '.$name, 'is_active' => true]));
            $reviewerRole = Role::findOrCreate('demo_evaluation_reviewer', 'web');
            foreach (['view_any_course::offering', 'view_course::offering', 'view_any_form', 'view_form', 'view_any_department', 'page_EvaluationResults', 'page_MyProfilePage'] as $permission) {
                $reviewerRole->givePermissionTo(Permission::findOrCreate($permission, 'web'));
            }
            foreach (['dean', 'chair'] as $title) {
                $reviewer = $this->account("demo.{$title}@ccis.test", 'Demo', ucfirst($title), $campus);
                $reviewer->assignRole($reviewerRole);
            }
            // Existing administrators gain only membership in this isolated demonstration campus.
            foreach (User::role('super_admin')->get() as $admin) {
                $admin->campuses()->syncWithoutDetaching([$campus->id]);
            }
            $form = Form::firstOrCreate(['slug' => 'demo-presentation-teaching-evaluation'], [
                'name' => 'DEMO Teaching Evaluation — Presentation Sample',
                'description' => 'SYNTHETIC DEMONSTRATION DATA ONLY. Rate each statement from 1 (Poor) to 5 (Outstanding). These are not actual faculty results or an official validated instrument.',
                'is_active' => true, 'requires_login' => true, 'evaluator_roles' => ['student'],
                'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
                'rating_scale' => collect(['Poor', 'Fair', 'Satisfactory', 'Very Satisfactory', 'Outstanding'])->map(fn ($label, $index) => ['value' => $index + 1, 'label' => $label])->all(),
                'scoring_config' => ['overall_method' => 'average', 'field_comments' => 'optional'],
            ]);
            $form->campuses()->syncWithoutDetaching([$campus->id]);
            $sections = [
                'Preparation' => ['Explains the learning objectives clearly.', 'Organizes lessons in a logical sequence.', 'Comes prepared with relevant learning materials.'],
                'Instruction' => ['Explains concepts using understandable examples.', 'Encourages questions and student participation.', 'Connects lessons with practical applications.'],
                'Assessment' => ['Explains assessment criteria before tasks.', 'Provides useful feedback on student work.', 'Uses assessments aligned with lesson objectives.'],
                'Learning Environment' => ['Treats students respectfully.', 'Provides fair opportunities to participate.', 'Responds constructively to learning difficulties.'],
            ];
            $order = 0;
            foreach ($sections as $section => $questions) {
                foreach ($questions as $label) {
                    $order++;
                    $form->fields()->firstOrCreate(['name' => 'demo_rating_'.$order], ['label' => $label, 'section' => $section, 'type' => 'likert_scale', 'is_required' => true, 'order' => $order]);
                }
            }
            $students = collect();
            for ($number = 1; $number <= 12; $number++) {
                $student = $this->account(sprintf('demo.student%02d@ccis.test', $number), 'Demo Student', sprintf('%02d', $number), $campus, ['is_student' => true, 'student_id' => sprintf('DEMO-2026-%03d', $number)]);
                $student->assignRole(Role::findOrCreate('student', 'web'));
                if ($student->wasRecentlyCreated && $number !== 12) {
                    $student->forceFill(['password' => Hash::make('482619'), 'pin_issued_at' => now()])->save();
                }
                $students->push($student);
            }
            foreach (['Rivera', 'Santos', 'Cruz'] as $index => $surname) {
                $department = $departments[$index === 2 ? 1 : 0];
                $faculty = $this->account('demo.faculty'.($index + 1).'@ccis.test', 'Demo Faculty', $surname, $campus, ['is_instructor' => true, 'instructor_department_id' => $department->id]);
                $faculty->assignRole(Role::findOrCreate('instructor', 'web'));
                $course = Course::firstOrCreate(['department_id' => $department->id, 'code' => 'DEMO-COURSE-'.($index + 1)], ['name' => ['Introduction to Computing', 'Information Management', 'Communication Skills'][$index], 'units' => 3, 'is_active' => true]);
                $offering = CourseOffering::firstOrCreate(['semester_id' => $semester->id, 'course_id' => $course->id, 'section' => 'DEMO-A', 'user_id' => $faculty->id], ['form_id' => $form->id, 'is_active' => true]);
                $offering->students()->syncWithoutDetaching($students->mapWithKeys(fn ($student) => [$student->id => ['enrolled_at' => now()]])->all());
                foreach ($students->take([8, 6, 4][$index]) as $studentIndex => $student) {
                    $data = [];
                    for ($question = 1; $question <= 12; $question++) {
                        $data['demo_rating_'.$question] = match ($index) {
                            0 => 4 + (($question + $studentIndex) % 2),
                            1 => 3 + (($question + $studentIndex) % 3),
                            default => 2 + (($question + $studentIndex) % 3),
                        };
                    }
                    $data['_overall_comment'] = '[DEMO] Synthetic response for presentation: clear explanations and helpful feedback.';
                    $submission = FormSubmission::firstOrCreate(['submission_fingerprint' => hash_hmac('sha256', "evaluation:{$offering->id}:user:{$student->id}", config('evaluations.fingerprint_key'))], [
                        'course_offering_id' => $offering->id, 'form_id' => $form->id,
                        'department_id' => $department->id, 'data' => $data, 'submitted_at' => now()->subHours($studentIndex + 1),
                    ]);
                    if ($submission->wasRecentlyCreated || $submission->user_agent === 'DEMO synthetic presentation seeder'
                        || str_starts_with($submission->data['_overall_comment'] ?? '', '[DEMO]')) {
                        $submission->forceFill(['user_id' => null, 'user_agent' => null, 'data' => $data])->save();
                    }
                }
            }
            $this->command?->info("DEMO campus {$campus->id}: 2 departments, 3 faculty, 12 students, 3 assignments and 18 synthetic responses. Existing records preserved.");
        });
    }

    private function account(string $email, string $first, string $last, Campus $campus, array $attributes = []): User
    {
        $user = User::firstOrCreate(['email' => $email], [...$attributes, 'first_name' => $first, 'last_name' => $last, 'password' => Hash::make('DemoEval!2026')]);
        $user->campuses()->syncWithoutDetaching([$campus->id]);

        return $user;
    }
}
