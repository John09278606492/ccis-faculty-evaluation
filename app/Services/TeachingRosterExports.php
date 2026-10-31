<?php

namespace App\Services;

use App\Filament\Resources\TeachingClassResource;
use App\Models\BackgroundTask;
use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class TeachingRosterExports
{
    public function query(User $actor, Campus $campus): Builder
    {
        return app(AssignedDataAccess::class)->apply(CourseOffering::withoutGlobalScope('assigned_data'), $actor, $campus)
            ->whereHas('semester.schoolYear', fn ($q) => $q->where('campus_id', $campus->id))
            ->whereNotNull('course_id')->where('is_active', true)
            ->whereHas('evaluatee', fn ($q) => $q->where('is_instructor', true)->where('is_student', false))
            ->where(fn ($q) => $q->whereDoesntHave('evaluationForm')->orWhereHas('evaluationForm', fn ($f) => $f->where('settings->annex', '!=', 'B')->orWhereNull('settings->annex')));
    }

    private function sameFacultySubject(CourseOffering $class, CourseOffering $shared): bool
    {
        return $class->user_id === $shared->user_id && $class->semester_id === $shared->semester_id
            && $class->evaluation_cycle_id === $shared->evaluation_cycle_id
            && ImportedCourseCatalogue::codeKey($class->course->code) === ImportedCourseCatalogue::codeKey($shared->course->code)
            && StudentAcademicRecords::code($class->course->name) === StudentAcademicRecords::code($shared->course->name);
    }

    public function generate(BackgroundTask $task, User $actor, Campus $campus): array
    {
        $tasks = app(BackgroundTasks::class);
        $ids = array_values(array_unique(array_map('intval', $task->payload['ids'] ?? [])));
        $query = $this->query($actor, $campus)->whereKey($ids);
        abort_unless((clone $query)->count() === count($ids), 403, 'Selected teaching classes or access changed before export.');
        $periods = (clone $query)->distinct()->pluck('semester_id')->all();
        $relations = ['course.department', 'teachingDepartment', 'evaluatee.instructorDepartment', 'semester.schoolYear',
            'students' => fn ($q) => $q->select('users.id'),
            'students.studentSectionMemberships' => fn ($q) => $q->whereIn('semester_id', $periods)];
        $rows = 0;
        $faculty = $scope = $requirements = [];
        $generated = now()->toIso8601String();
        $tasks->csv($task, 'instructors-missing-sections-or-students-'.now()->format('Y-m-d').'.csv', function ($handle) use ($tasks, $actor, $campus, $query, $relations, $generated, &$rows, &$faculty, &$scope, &$requirements) {
            $tasks->row($handle, ['Instructor', 'Email', 'Employee ID', 'Home Department', 'Teaching Department', 'Academic Period', 'Subject Code', 'Subject', 'Class Reference', 'Assigned Sections', 'Assigned Students', 'Students Without Section', 'Follow-up Needed', 'Next Step', 'Teaching Class ID', 'Review Link', 'Generated At']);
            $query->with($relations)->withCount('students')->orderBy('id')->chunkById(50, function ($classes) use ($tasks, $actor, $campus, $handle, $relations, $generated, &$rows, &$faculty, &$scope, &$requirements) {
                $sharedIds = $classes->filter(fn ($class) => ! $class->students_count && TeachingClassResource::sharedCountLabel($class) !== 0)
                    ->map(fn ($class) => (int) $class->evaluator_selection['auto_match']['grouped_class_id'])->unique()->all();
                $shared = $this->query($actor, $campus)->whereKey($sharedIds)->with($relations)->withCount('students')->get()->keyBy('id');
                foreach ($classes as $class) {
                    $roster = $class;
                    $candidate = $shared->get((int) ($class->evaluator_selection['auto_match']['grouped_class_id'] ?? 0));
                    if (! $class->students_count && TeachingClassResource::sharedCountLabel($class) !== 0 && $candidate && $this->sameFacultySubject($class, $candidate)) {
                        $roster = $candidate;
                    }
                    $key = $roster->course_id.'|'.$roster->semester_id;
                    $requireMatch = $requirements[$key] ??= app(EvaluationSections::class)->requiresSection($roster);
                    $labels = $roster->students->map(fn ($student) => app(EvaluationSections::class)->forStudent($roster, $student, $requireMatch));
                    $pending = $labels->filter(fn ($label) => blank($label))->count();
                    $sections = $labels->filter()->unique()->values()->all();
                    if (! $sections) {
                        $sections = array_values(array_unique(array_filter(array_map(fn ($section) => rawurldecode(explode('~', $section, 3)[2] ?? ''), $class->evaluator_selection['sections'] ?? []))));
                    }
                    $issues = $steps = [];
                    if (! $roster->students_count) {
                        $issues[] = 'No students assigned';
                        $steps[] = 'Upload the section student list, or link its existing students to this teaching class.';
                    }
                    if (! $sections) {
                        $issues[] = 'No section assigned';
                        $steps[] = 'Assign the student section in Teaching Classes.';
                    } elseif ($pending) {
                        $issues[] = 'Some students have no confirmed section';
                        $steps[] = 'Finish sectioning the assigned students; their IDs are already present.';
                    }
                    $auto = ($roster->evaluator_selection['source'] ?? '') === 'auto_enrolment_v1' ? ($roster->evaluator_selection['auto_match'] ?? []) : [];
                    if (($auto['ambiguous'] ?? 0) || ($auto['section_required'] ?? 0)) {
                        $issues[] = 'Teacher/section matching needs review';
                        $steps[] = 'Resolve students for subjects handled by multiple instructors.';
                    }
                    if (! $issues) {
                        continue;
                    }
                    $tasks->row($handle, [$class->evaluatee->name, $class->evaluatee->email, $class->evaluatee->employee_id,
                        $class->evaluatee->instructorDepartment?->code, $class->teachingDepartment?->code ?? $class->course->department?->code,
                        $class->semester->name.' / '.$class->semester->schoolYear->name, $class->course->code, $class->course->name, $class->section,
                        implode('; ', $sections), $roster->students_count, $pending, implode('; ', $issues), implode(' ', array_unique($steps)), $class->id,
                        url('/'.$campus->id.'/teaching-classes/'.$roster->id.'/edit?activeRelationManager=0'), $generated]);
                    $faculty[$class->user_id] = true;
                    $scope[$class->id] = $scope[$roster->id] = true;
                    $rows++;
                }
            });
        });

        return ['scope_ids' => array_keys($scope), 'row_count' => $rows, 'instructor_count' => count($faculty), 'generated_at' => $generated,
            'message' => $rows ? count($faculty).' instructors across '.$rows.' teaching classes need section or student-list follow-up. Your private CSV is ready.'
                : 'No missing section or student-list assignments were found in the filtered active teaching classes. Your CSV contains the column headings.'];
    }
}
