<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\EvaluationCycle;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SupervisorEvaluationAssignments
{
    public function prepare(User $head, Campus $campus, ?EvaluationCycle $cycle = null): void
    {
        if ($head->is_student || ! $head->canAccessTenant($campus)) {
            return;
        }
        $assigned = CourseOffering::withoutGlobalScope('assigned_data')->whereNotNull('course_id')->where('is_active', true)->where('user_id', '!=', $head->id)
            ->whereHas('semester.schoolYear', fn ($q) => $q->where('campus_id', $campus->id))
            ->whereHas('supervisorEvaluators', fn ($q) => $q->withoutGlobalScope('assigned_data')->whereKey($head->id))
            ->when($cycle, fn ($q) => $q->where('evaluation_cycle_id', $cycle->id), fn ($q) => $q->whereNull('evaluation_cycle_id')->where('semester_id', AcademicPeriod::current($campus)))->get();
        foreach ($assigned as $source) {
            $this->prepareSource($source);
        }
    }

    public function prepareSource(CourseOffering $source): void
    {
        $source = $source->fresh();
        if (! $source) {
            return;
        }
        $source->loadMissing('course', 'evaluationForm', 'evaluationCycle', 'semester.schoolYear.campus');
        if (! $source->course_id || ! $source->is_active || ! $source->evaluationForm?->is_active || $source->evaluationForm->isSupervisorInstrument()
            || ! app(FacultySupervisorEvaluators::class)->candidates($source)->whereKey($source->supervisorEvaluators()->withoutGlobalScope('assigned_data')->pluck('users.id'))->exists()
            || ($source->evaluation_cycle_id && $source->evaluationCycle?->status !== 'published')) {
            return;
        }
        $this->createTask($source->campus, (int) $source->user_id, (int) $source->semester_id, (int) ($source->teaching_department_id ?? $source->course->department_id), $source->evaluationCycle);
    }

    /**
     * Creates the Annex B tasks a chairperson rates automatically in a period: faculty whose home department they chair,
     * and faculty teaching a class in it. Safe to call on every visit; existing tasks are left as they are.
     */
    public function prepareChairperson(User $chair, Campus $campus, ?int $semesterId = null): void
    {
        $semesterId ??= AcademicPeriod::current($campus);
        if (! $semesterId || $chair->is_student || ! $chair->canAccessTenant($campus)) {
            return;
        }
        foreach (app(FacultySupervisorEvaluators::class)->chairedDepartments($chair, $campus)->get() as $department) {
            $classes = DB::table('course_offerings as class')->join('courses as course', 'course.id', '=', 'class.course_id')
                ->where('class.semester_id', $semesterId)->where('class.is_active', true)->where('class.user_id', '!=', $chair->id)
                ->select('class.user_id', 'class.evaluation_cycle_id', DB::raw('COALESCE(class.teaching_department_id, course.department_id) as department_id'))->get();
            $teaching = $classes->where('department_id', $department->id)->groupBy('user_id');
            $home = DB::table('users')->where('instructor_department_id', $department->id)->where('is_instructor', true)->whereNull('deleted_at')
                ->whereIn('id', $classes->pluck('user_id')->unique())->pluck('id');
            $ownCycle = EvaluationCycle::where('department_id', $department->id)->where('semester_id', $semesterId)->whereIn('status', ['draft', 'published'])->latest('id')->first();
            $withTask = DB::table('course_offerings as task')->join('forms', 'forms.id', '=', 'task.form_id')->where('forms.settings->annex', 'B')
                ->where('task.semester_id', $semesterId)->where('task.teaching_department_id', $department->id)->pluck('task.user_id')->flip();
            foreach ($teaching->keys()->merge($home)->unique() as $facultyId) {
                if ($withTask->has($facultyId)) {
                    continue;
                }
                // A task follows the evaluation its classes in this department belong to; a home-only task follows the department's own evaluation.
                $cycleId = $teaching->get($facultyId)?->pluck('evaluation_cycle_id')->filter()->first() ?? $ownCycle?->id;
                $cycle = $cycleId ? EvaluationCycle::find($cycleId) : null;
                if ($cycle && $cycle->status !== 'published') {
                    continue;
                }
                $this->createTask($campus, (int) $facultyId, $semesterId, (int) $department->id, $cycle);
            }
        }
    }

    private function createTask(Campus $campus, int $facultyId, int $semesterId, int $departmentId, ?EvaluationCycle $cycle): void
    {
        DB::transaction(function () use ($campus, $facultyId, $semesterId, $departmentId, $cycle) {
            $department = Department::withoutGlobalScope('assigned_data')->whereKey($departmentId)->lockForUpdate()->firstOrFail();
            $existing = CourseOffering::withoutGlobalScope('assigned_data')->where('user_id', $facultyId)->where('semester_id', $semesterId)
                ->when($cycle, fn ($q) => $q->where('evaluation_cycle_id', $cycle->id), fn ($q) => $q->whereNull('evaluation_cycle_id'))
                ->whereHas('evaluationForm', fn ($q) => $q->withoutGlobalScope('assigned_data')->where('settings->annex', 'B'))
                ->where(fn ($q) => $this->inDepartment($q, $department))->exists();
            if ($existing) {
                return;
            }
            $form = $cycle?->supervisorForm ?? $campus->forms()->where('is_active', true)->where('settings->annex', 'B')
                ->where(fn ($q) => $q->whereDoesntHave('departments')->orWhereHas('departments', fn ($d) => $d->where('departments.id', $department->id)))->first();
            if (! $form) {
                $form = app(EvaluationTemplates::class)->create('B', $campus);
                $form->update(['name' => 'Annex B — '.$department->code.' Supervisor Evaluation', 'is_active' => true]);
                $form->departments()->attach($department);
            }
            CourseOffering::create(['semester_id' => $semesterId, 'course_id' => null, 'teaching_department_id' => $department->id,
                'evaluation_cycle_id' => $cycle?->id, 'user_id' => $facultyId, 'section' => 'SEF-'.$department->code,
                'form_id' => $form->id, 'evaluation_type' => 'SEF', 'is_active' => true]);
        });
    }

    public function forViewer(User $user, Campus $campus)
    {
        return CourseOffering::query()->where('is_active', true)->where('user_id', '!=', $user->id)
            ->whereHas('semester.schoolYear', fn ($q) => $q->where('campus_id', $campus->id))
            ->whereHas('evaluationForm', fn ($q) => $q->where('is_active', true)->where('settings->annex', 'B'))
            ->where('semester_id', AcademicPeriod::current($campus))
            ->whereIn('id', app(FacultySupervisorEvaluators::class)->taskIds($user, $campus));
    }

    private function inDepartment(Builder $query, Department $department): Builder
    {
        return $query->where('teaching_department_id', $department->id)
            ->orWhere(fn ($fallback) => $fallback->whereNull('teaching_department_id')
                ->where(fn ($fallback) => $fallback->whereHas('course', fn ($course) => $course->where('department_id', $department->id))
                    ->orWhere(fn ($general) => $general->whereNull('course_id')->whereHas('evaluatee', fn ($faculty) => $faculty->where('instructor_department_id', $department->id)))));
    }
}
