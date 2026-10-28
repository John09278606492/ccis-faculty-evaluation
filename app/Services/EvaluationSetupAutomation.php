<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\EvaluationCycle;
use App\Models\Form;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Draft automation selects only authorized, prepared classes; publication stays explicit. */
class EvaluationSetupAutomation
{
    private array $plans = [];

    public function classQuery(User $actor, Campus $campus, int $departmentId, int $semesterId, ?EvaluationCycle $cycle = null)
    {
        abort_unless(app(EvaluationCycles::class)->allowed($actor, $campus), 403);
        app(AssignedDataAccess::class)->apply(Department::query(), $actor, $campus)->findOrFail($departmentId);
        AcademicPeriod::validate($campus, $semesterId);

        return app(AssignedDataAccess::class)->apply(CourseOffering::query(), $actor, $campus)->where('semester_id', $semesterId)->whereNotNull('course_id')
            ->where(fn ($q) => $q->where('teaching_department_id', $departmentId)
                ->orWhere(fn ($q) => $q->whereNull('teaching_department_id')->whereHas('course', fn ($c) => $c->where('department_id', $departmentId))))
            ->where(fn ($q) => $q->whereNull('evaluation_cycle_id')->when($cycle, fn ($q) => $q->orWhere('evaluation_cycle_id', $cycle->id)))
            ->where(fn ($q) => $q->whereDoesntHave('evaluationForm')->orWhereHas('evaluationForm', fn ($f) => $f->withoutGlobalScope('assigned_data')->where('settings->annex', '!=', 'B')->orWhereNull('settings->annex')));
    }

    public function plan(User $actor, Campus $campus, ?int $departmentId, ?int $semesterId, ?EvaluationCycle $cycle = null): array
    {
        if (! $departmentId || ! $semesterId) {
            return ['classes' => collect(), 'prepared' => collect(), 'excluded' => []];
        }
        $key = implode(':', [$actor->id, $campus->id, $departmentId, $semesterId, $cycle?->id]);
        if (isset($this->plans[$key])) {
            return $this->plans[$key];
        }
        $classes = $this->classQuery($actor, $campus, $departmentId, $semesterId, $cycle)
            ->with(['course' => fn ($q) => $q->withoutGlobalScope('assigned_data'), 'evaluationForm', 'evaluatee' => fn ($q) => $q->withoutGlobalScope('assigned_data'), 'students.studentSectionMemberships'])
            ->withExists(['formSubmissions' => fn ($q) => $q->withoutGlobalScope('assigned_data'), 'ratings', 'quizzes'])->get();
        $excluded = [];
        $prepared = $classes->filter(function ($class) use (&$excluded, $cycle) {
            $reason = null;
            if (! $class->is_active) {
                $reason = 'Inactive class';
            } elseif (! $class->evaluatee || $class->evaluatee->trashed() || ! $class->evaluatee->is_instructor || $class->evaluatee->is_student) {
                $reason = 'Faculty profile needs correction';
            } elseif ($class->students->isEmpty()) {
                $reason = 'No students assigned';
            } elseif ((! $cycle || $class->evaluation_cycle_id !== $cycle->id) && ($class->form_submissions_exists || $class->ratings_exists || $class->quizzes_exists)) {
                $reason = 'Existing evaluation or assessment records';
            } elseif (app(EvaluationSections::class)->requiresSection($class) && $class->students->contains(fn ($s) => ! app(EvaluationSections::class)->forStudent($class, $s, true))) {
                $reason = 'Student sections need confirmation';
            }
            if ($reason) {
                $excluded[] = ['id' => $class->id, 'label' => $class->display_name, 'reason' => $reason];
            }

            return $reason === null;
        })->values();
        $seen = $overlap = [];
        foreach ($prepared as $class) {
            foreach ($class->students as $student) {
                $claim = $class->user_id.'|'.ImportedCourseCatalogue::codeKey($class->course->code).'|'.StudentAcademicRecords::code($class->course->name).'|'.$student->id;
                if (isset($seen[$claim]) && $seen[$claim] !== $class->id) {
                    $overlap[$class->id] = true;
                    $overlap[$seen[$claim]] = true;
                } else {
                    $seen[$claim] = $class->id;
                }
            }
        }
        foreach ($prepared as $class) {
            if (isset($overlap[$class->id])) {
                $excluded[] = ['id' => $class->id, 'label' => $class->display_name, 'reason' => 'Overlapping student rosters for the same faculty and subject'];
            }
        }
        $prepared = $prepared->reject(fn ($c) => isset($overlap[$c->id]))->values();

        return $this->plans[$key] = compact('classes', 'prepared', 'excluded');
    }

    public function resolve(User $actor, Campus $campus, int $departmentId, int $semesterId, string $mode, array $facultyIds, ?EvaluationCycle $cycle): array
    {
        $plan = $this->plan($actor, $campus, $departmentId, $semesterId, $cycle);
        $classes = $plan['prepared'];
        if ($mode === 'faculty') {
            $facultyIds = array_values(array_unique(array_map('intval', $facultyIds)));
            if (! $facultyIds || array_diff($facultyIds, $classes->pluck('user_id')->unique()->all())) {
                throw ValidationException::withMessages(['faculty_ids' => 'Choose faculty with prepared teaching classes in this department and academic period.']);
            }
            $classes = $classes->whereIn('user_id', $facultyIds);
        }
        // Existing responses must never be silently orphaned by draft automation.
        $retained = $plan['classes']->filter(fn ($c) => $cycle && $c->evaluation_cycle_id === $cycle->id && ($c->form_submissions_exists || $c->ratings_exists || $c->quizzes_exists))->modelKeys();

        return array_values(array_unique([...$classes->modelKeys(), ...$retained]));
    }

    public function defaults(User $actor, Campus $campus, ?int $departmentId, ?int $semesterId): array
    {
        if (! $departmentId || ! $semesterId) {
            return [];
        }
        $department = app(AssignedDataAccess::class)->apply(Department::query(), $actor, $campus)->findOrFail($departmentId);
        AcademicPeriod::validate($campus, $semesterId);
        $result = ['name' => $department->code.' Faculty Evaluation — '.AcademicPeriod::options($campus)[$semesterId]];
        $previous = app(EvaluationCycles::class)->query($actor, $campus)->where('department_id', $departmentId)
            ->orderByRaw('CASE WHEN semester_id = ? THEN 0 ELSE 1 END', [$semesterId])->latest('id')->first();
        foreach (['student_form_id' => 'A', 'supervisor_form_id' => 'B'] as $field => $annex) {
            $forms = app(AssignedDataAccess::class)->apply(Form::query(), $actor, $campus)->whereNull('archived_at')->where('settings->annex', $annex)
                ->where(fn ($q) => $q->whereDoesntHave('departments')->orWhereHas('departments', fn ($d) => $d->whereKey($departmentId)))->get();
            $result[$field] = $previous && $forms->contains('id', $previous->$field) ? $previous->$field : ($forms->count() === 1 ? $forms->first()->id : null);
        }

        return $result;
    }
}
