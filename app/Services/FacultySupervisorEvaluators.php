<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who rates a faculty with Annex B. A department's chairperson does so automatically for faculty whose home department
 * it is and for faculty teaching a class there in that period. Supervisors selected on a teaching class are added to that.
 */
class FacultySupervisorEvaluators
{
    public function candidates(CourseOffering $class)
    {
        $class->loadMissing('semester.schoolYear.campus');

        return User::withoutGlobalScope('assigned_data')->where('is_student', false)->where('id', '!=', $class->user_id)
            ->whereHas('roles')->whereHas('campuses', fn ($q) => $q->whereKey($class->campus->id))->orderBy('name');
    }

    public function save(User $actor, CourseOffering $class, array $ids): void
    {
        $class = $class->fresh();
        abort_unless($class->course_id && ! $class->evaluationForm?->isSupervisorInstrument()
            && $actor->can('manageEvaluators', $class)
            && app(AssignedDataAccess::class)->apply(CourseOffering::query(), $actor, $class->campus)->whereKey($class->id)->exists(), 403);
        abort_if($class->evaluationCycle && ! in_array($class->evaluationCycle->status, ['draft', 'published'], true), 409, 'This evaluation is closed.');
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($this->candidates($class)->whereKey($ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['supervisor_ids' => 'Choose staff from this campus, excluding the faculty evaluated.']);
        }
        DB::transaction(function () use ($class, $ids) {
            $class->supervisorEvaluators()->sync($ids);
            $class->touch();
            if ($ids) {
                app(SupervisorEvaluationAssignments::class)->prepareSource($class);
            }
            ClassroomUpdates::panel('setup');
        });
    }

    public function sourcesForTask(CourseOffering $task)
    {
        $task->loadMissing(['course', 'evaluatee' => fn ($q) => $q->withoutGlobalScope('assigned_data')]);
        $department = $task->teaching_department_id ?? $task->course?->department_id ?? $task->evaluatee?->instructor_department_id;

        return DB::table('course_offerings as source')->join('courses as source_course', 'source_course.id', '=', 'source.course_id')
            ->where('source.user_id', $task->user_id)->where('source.semester_id', $task->semester_id)
            ->where('source.is_active', true)->whereRaw('COALESCE(source.teaching_department_id, source_course.department_id) = ?', [$department])
            ->when($task->evaluation_cycle_id, fn ($q) => $q->where('source.evaluation_cycle_id', $task->evaluation_cycle_id), fn ($q) => $q->whereNull('source.evaluation_cycle_id'))
            ->select('source.id');
    }

    public function forTask(CourseOffering $task)
    {
        $task->loadMissing('semester.schoolYear.campus');
        $chairperson = $this->automaticChairpersonId($task);

        return User::withoutGlobalScope('assigned_data')->where('is_student', false)->where('id', '!=', $task->user_id)->whereHas('roles')
            ->whereHas('campuses', fn ($q) => $q->whereKey($task->campus->id))
            ->where(fn ($q) => $q->whereIn('users.id', DB::table('course_offering_supervisor')->select('user_id')->whereIn('course_offering_id', $this->sourcesForTask($task)))
                ->when($chairperson, fn ($q) => $q->orWhere('users.id', $chairperson)));
    }

    /** Active departments of the campus the user chairs. */
    public function chairedDepartments(User $user, Campus $campus)
    {
        return Department::withoutGlobalScope('assigned_data')->where('campus_id', $campus->id)->where('is_active', true)->where('chairperson_id', $user->id);
    }

    /** The chairperson who rates this task's faculty automatically, unless that is the faculty themself. Mirrors the second rule in taskIds(). */
    public function automaticChairpersonId(CourseOffering $task): ?int
    {
        $task->loadMissing(['course', 'evaluatee' => fn ($q) => $q->withoutGlobalScope('assigned_data')]);
        $departmentId = $task->teaching_department_id ?? $task->course?->department_id ?? $task->evaluatee?->instructor_department_id;
        $chairperson = $departmentId ? DB::table('departments')->where('id', $departmentId)->where('is_active', true)->value('chairperson_id') : null;
        if (! $chairperson || (int) $chairperson === (int) $task->user_id) {
            return null;
        }
        $home = (int) $task->evaluatee?->instructor_department_id === (int) $departmentId;
        $teaches = DB::table('course_offerings as taught')->join('courses as taught_course', 'taught_course.id', '=', 'taught.course_id')
            ->where('taught.user_id', $task->user_id)->where('taught.semester_id', $task->semester_id)->where('taught.is_active', true)
            ->whereRaw('COALESCE(taught.teaching_department_id, taught_course.department_id) = ?', [$departmentId])->exists();

        return $home || $teaches ? (int) $chairperson : null;
    }

    public function taskIds(User $user, Campus $campus)
    {
        $query = DB::table('course_offerings as task')->leftJoin('courses as task_course', 'task_course.id', '=', 'task.course_id')
            ->join('users as faculty', 'faculty.id', '=', 'task.user_id')->select('task.id')
            ->where('task.user_id', '!=', $user->id)->where('task.is_active', true)
            ->whereIn('task.semester_id', DB::table('semesters')->select('id')->whereIn('school_year_id', DB::table('school_years')->select('id')->where('campus_id', $campus->id)))
            ->whereIn('task.form_id', DB::table('forms')->select('id')->where('settings->annex', 'B'))
            ->where(fn ($rule) => $rule->whereExists(function ($q) use ($user) {
                // Selected on one of the faculty's teaching classes in the task's department and evaluation.
                $q->selectRaw('1')->from('course_offering_supervisor as delegation')
                    ->join('course_offerings as source', 'source.id', '=', 'delegation.course_offering_id')
                    ->join('courses as source_course', 'source_course.id', '=', 'source.course_id')
                    ->where('delegation.user_id', $user->id)->where('source.is_active', true)
                    ->whereColumn('source.user_id', 'task.user_id')->whereColumn('source.semester_id', 'task.semester_id')
                    ->whereRaw('COALESCE(source.teaching_department_id, source_course.department_id) = COALESCE(task.teaching_department_id, task_course.department_id, faculty.instructor_department_id)')
                    ->where(fn ($q) => $q->whereColumn('source.evaluation_cycle_id', 'task.evaluation_cycle_id')
                        ->orWhere(fn ($q) => $q->whereNull('source.evaluation_cycle_id')->whereNull('task.evaluation_cycle_id')));
            })->orWhereExists(function ($q) use ($user, $campus) {
                // Chairperson of the task's department, which is the faculty's home department or one they teach in that period.
                $q->selectRaw('1')->from('departments as lead')
                    ->where('lead.chairperson_id', $user->id)->where('lead.is_active', true)->where('lead.campus_id', $campus->id)
                    ->whereRaw('lead.id = COALESCE(task.teaching_department_id, task_course.department_id, faculty.instructor_department_id)')
                    ->where(fn ($q) => $q->whereColumn('faculty.instructor_department_id', 'lead.id')
                        ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('course_offerings as taught')
                            ->join('courses as taught_course', 'taught_course.id', '=', 'taught.course_id')
                            ->whereColumn('taught.user_id', 'task.user_id')->whereColumn('taught.semester_id', 'task.semester_id')->where('taught.is_active', true)
                            ->whereRaw('COALESCE(taught.teaching_department_id, taught_course.department_id) = lead.id')));
            }));
        if ($user->isStudent() || $user->trashed() || ! $user->campuses()->whereKey($campus->id)->exists() || ! $user->roles()->exists()) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }
}
