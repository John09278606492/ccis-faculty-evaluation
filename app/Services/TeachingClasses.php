<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TeachingClasses
{
    public function save(User $actor, Campus $campus, array $data, ?CourseOffering $record = null): CourseOffering
    {
        abort_unless(! $actor->isStudent() && $actor->canAccessTenant($campus) && $actor->can($record ? 'update_course::offering' : 'create_course::offering'), 403);
        if ($record) {
            abort_unless(app(AssignedDataAccess::class)->apply(CourseOffering::query(), $actor, $campus)->whereKey($record->id)->exists(), 403);
        }
        $data = Validator::make($data, [
            'semester_id' => ['required', Rule::in(array_keys(AcademicPeriod::options($campus)))],
            'course_id' => ['required', Rule::in(app(AssignedDataAccess::class)->apply(Course::query(), $actor, $campus)->pluck('id'))],
            'user_id' => ['required', Rule::in(DB::table('users')->where('is_instructor', true)->whereIn('id', DB::table('campus_user')->select('user_id')->where('campus_id', $campus->id))->pluck('id'))],
            'section' => 'nullable|string|max:20', 'is_active' => 'boolean',
        ])->validate();
        $identityChanged = $record && collect($data)->only(['semester_id', 'course_id', 'user_id'])->contains(fn ($v, $k) => (int) $v !== (int) $record->$k);
        if ($identityChanged) {
            $record->loadMissing('course');
            $departmentId = $record->teaching_department_id ?? $record->course->department_id;
            $hasSupervisorTask = CourseOffering::withoutGlobalScope('assigned_data')->where('user_id', $record->user_id)->where('semester_id', $record->semester_id)
                ->when($record->evaluation_cycle_id, fn ($q) => $q->where('evaluation_cycle_id', $record->evaluation_cycle_id), fn ($q) => $q->whereNull('evaluation_cycle_id'))
                ->whereHas('evaluationForm', fn ($q) => $q->withoutGlobalScope('assigned_data')->where('settings->annex', 'B'))
                ->where(fn ($q) => $q->where('teaching_department_id', $departmentId)->orWhere(fn ($q) => $q->whereNull('teaching_department_id')
                    ->where(fn ($q) => $q->whereHas('course', fn ($q) => $q->withoutGlobalScope('assigned_data')->where('department_id', $departmentId))
                        ->orWhere(fn ($q) => $q->whereNull('course_id')->whereHas('evaluatee', fn ($q) => $q->withoutGlobalScope('assigned_data')->where('instructor_department_id', $departmentId))))))->exists();
            abort_if($record->evaluation_cycle_id || $record->formSubmissions()->exists() || $record->quizzes()->exists() || $hasSupervisorTask,
                409, 'This class has prepared evaluations or assessment records. Create a new class for a different course, faculty member or academic period.');
        }
        $record ??= new CourseOffering;

        return DB::transaction(function () use ($record, $data, $identityChanged) {
            $record->fill([...$data, 'teaching_department_id' => Course::findOrFail($data['course_id'])->department_id]);
            if ($identityChanged) {
                $record->fill(['form_id' => null, 'evaluation_type' => null, 'evaluator_selection' => null]);
            }
            $record->save();
            if ($identityChanged) {
                $record->students()->detach();
                $record->supervisorEvaluators()->detach();
            }

            return $record;
        });
    }
}
