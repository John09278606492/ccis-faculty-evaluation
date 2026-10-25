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
            || ! app(FacultySupervisorEvaluators::class)->candidates($source)->whereKey($source->supervisorEvaluators()->pluck('users.id'))->exists()
            || ($source->evaluation_cycle_id && $source->evaluationCycle?->status !== 'published')) {
            return;
        }
        $departmentId = $source->teaching_department_id ?? $source->course->department_id;
        DB::transaction(function () use ($source, $departmentId) {
            $department = Department::withoutGlobalScope('assigned_data')->whereKey($departmentId)->lockForUpdate()->firstOrFail();
            $cycle = $source->evaluationCycle;
            $existing = CourseOffering::withoutGlobalScope('assigned_data')->where('user_id', $source->user_id)->where('semester_id', $source->semester_id)
                ->when($cycle, fn ($q) => $q->where('evaluation_cycle_id', $cycle->id), fn ($q) => $q->whereNull('evaluation_cycle_id'))
                ->whereHas('evaluationForm', fn ($q) => $q->withoutGlobalScope('assigned_data')->where('settings->annex', 'B'))
                ->where(fn ($q) => $this->inDepartment($q, $department))->exists();
            if ($existing) {
                return;
            }
            $form = $cycle?->supervisorForm ?? $source->campus->forms()->where('is_active', true)->where('settings->annex', 'B')
                ->where(fn ($q) => $q->whereDoesntHave('departments')->orWhereHas('departments', fn ($d) => $d->where('departments.id', $department->id)))->first();
            if (! $form) {
                $form = app(EvaluationTemplates::class)->create('B', $source->campus);
                $form->update(['name' => 'Annex B — '.$department->code.' Supervisor Evaluation', 'is_active' => true]);
                $form->departments()->attach($department);
            }
            CourseOffering::create(['semester_id' => $source->semester_id, 'course_id' => null, 'teaching_department_id' => $department->id,
                'evaluation_cycle_id' => $cycle?->id, 'user_id' => $source->user_id, 'section' => 'SEF-'.$department->code,
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
