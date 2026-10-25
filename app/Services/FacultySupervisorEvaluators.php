<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Delegation belongs to a teaching assignment, never to an entire department. */
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

        return User::withoutGlobalScope('assigned_data')->where('is_student', false)->where('id', '!=', $task->user_id)->whereHas('roles')
            ->whereHas('campuses', fn ($q) => $q->whereKey($task->campus->id))
            ->whereIn('users.id', DB::table('course_offering_supervisor')->select('user_id')->whereIn('course_offering_id', $this->sourcesForTask($task)));
    }

    public function taskIds(User $user, Campus $campus)
    {
        $query = DB::table('course_offerings as task')->leftJoin('courses as task_course', 'task_course.id', '=', 'task.course_id')
            ->join('users as faculty', 'faculty.id', '=', 'task.user_id')->select('task.id')
            ->where('task.user_id', '!=', $user->id)->where('task.is_active', true)
            ->whereIn('task.semester_id', DB::table('semesters')->select('id')->whereIn('school_year_id', DB::table('school_years')->select('id')->where('campus_id', $campus->id)))
            ->whereIn('task.form_id', DB::table('forms')->select('id')->where('settings->annex', 'B'))
            ->whereExists(function ($q) use ($user) {
                $q->selectRaw('1')->from('course_offering_supervisor as delegation')
                    ->join('course_offerings as source', 'source.id', '=', 'delegation.course_offering_id')
                    ->join('courses as source_course', 'source_course.id', '=', 'source.course_id')
                    ->where('delegation.user_id', $user->id)->where('source.is_active', true)
                    ->whereColumn('source.user_id', 'task.user_id')->whereColumn('source.semester_id', 'task.semester_id')
                    ->whereRaw('COALESCE(source.teaching_department_id, source_course.department_id) = COALESCE(task.teaching_department_id, task_course.department_id, faculty.instructor_department_id)')
                    ->where(fn ($q) => $q->whereColumn('source.evaluation_cycle_id', 'task.evaluation_cycle_id')
                        ->orWhere(fn ($q) => $q->whereNull('source.evaluation_cycle_id')->whereNull('task.evaluation_cycle_id')));
            });
        if ($user->isStudent() || $user->trashed() || ! $user->campuses()->whereKey($campus->id)->exists() || ! $user->roles()->exists()) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }
}
