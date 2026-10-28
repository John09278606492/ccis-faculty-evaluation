<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\EvaluationCycle;
use App\Models\Form;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EvaluationCycles
{
    public function allowed(User $actor, Campus $campus): bool
    {
        return ! $actor->isStudent() && $actor->canAccessTenant($campus)
            && $actor->can('manage_evaluators_course::offering') && $actor->can('update_course::offering')
            && app(AssignedDataAccess::class)->departmentIds($actor, $campus) !== [];
    }

    public function query(User $actor, Campus $campus)
    {
        $ids = app(AssignedDataAccess::class)->departmentIds($actor, $campus);

        return EvaluationCycle::where('campus_id', $campus->id)->when($ids !== null, fn ($q) => $q->whereIn('department_id', $ids));
    }

    public function save(User $actor, Campus $campus, array $data, ?EvaluationCycle $cycle = null): EvaluationCycle
    {
        abort_unless($this->allowed($actor, $campus), 403);
        if ($cycle) {
            abort_unless($this->query($actor, $campus)->whereKey($cycle->id)->exists() && $cycle->status === 'draft', 403);
        }

        return DB::transaction(function () use ($actor, $campus, $data, $cycle) {
            if (DB::getDriverName() === 'sqlite') {
                DB::table('campuses')->where('id', $campus->id)->update(['id' => $campus->id]);
            } else {
                Campus::whereKey($campus->id)->lockForUpdate()->firstOrFail();
            }
            if ($cycle) {
                $cycle = $this->query($actor, $campus)->findOrFail($cycle->id);
                abort_unless($cycle->status === 'draft', 403);
            }
            $departments = app(AssignedDataAccess::class)->apply(Department::query(), $actor, $campus)->pluck('id');
            $data = Validator::make($data, [
                'name' => 'required|string|max:255', 'department_id' => ['required', Rule::in($departments)],
                'semester_id' => ['required', Rule::in(array_keys(AcademicPeriod::options($campus)))],
                'student_form_id' => ['nullable', 'exists:forms,id'], 'supervisor_form_id' => ['nullable', 'exists:forms,id'],
                'starts_at' => 'nullable|date', 'ends_at' => ['nullable', 'date', Rule::when(filled($data['starts_at'] ?? null), 'after:starts_at')],
                'assignment_ids' => 'array', 'assignment_ids.*' => 'integer',
                'class_selection_mode' => ['sometimes', Rule::in(['prepared', 'faculty', 'manual'])],
                'faculty_ids' => 'sometimes|array', 'faculty_ids.*' => 'integer',
            ])->validate();
            $mode = $data['class_selection_mode'] ?? 'manual';
            $facultyIds = array_values(array_unique(array_map('intval', $data['faculty_ids'] ?? [])));
            if ($mode !== 'manual') {
                $data['assignment_ids'] = app(EvaluationSetupAutomation::class)->resolve($actor, $campus, (int) $data['department_id'], (int) $data['semester_id'], $mode, $facultyIds, $cycle);
            }
            $ids = array_values(array_unique($data['assignment_ids'] ?? []));
            foreach (['student_form_id' => 'A', 'supervisor_form_id' => 'B'] as $key => $annex) {
                if (! empty($data[$key])) {
                    $form = app(AssignedDataAccess::class)->apply(Form::query(), $actor, $campus)->find($data[$key]);
                    if (! $form || ($form->settings['annex'] ?? null) !== $annex
                        || ($form->departments()->exists() && ! $form->departments()->whereKey($data['department_id'])->exists())) {
                        throw ValidationException::withMessages([$key => 'Choose the matching Annex instrument for this department.']);
                    }
                }
            }
            $eligible = app(EvaluationSetupAutomation::class)->classQuery($actor, $campus, (int) $data['department_id'], (int) $data['semester_id'], $cycle);
            abort_unless((clone $eligible)->whereKey($ids)->count() === count($ids), 403, 'Select classes in this department and academic period only.');
            unset($data['assignment_ids'], $data['class_selection_mode'], $data['faculty_ids']);
            $data['class_selection'] = ['mode' => $mode, 'faculty_ids' => $mode === 'faculty' ? $facultyIds : []];

            return DB::transaction(function () use ($cycle, $campus, $data, $ids) {
                $cycle ??= new EvaluationCycle;
                $cycle->fill([...$data, 'campus_id' => $campus->id]);
                $cycle->save();
                $cycle->assignments()->whereNotIn('id', $ids)->whereDoesntHave('formSubmissions')->update(['evaluation_cycle_id' => null]);
                CourseOffering::whereKey($ids)->update(['evaluation_cycle_id' => $cycle->id]);

                return $cycle;
            });
        });
    }

    public function readiness(EvaluationCycle $cycle): array
    {
        $cycle->loadMissing(['department.supervisor', 'department.chairperson', 'studentForm.fields', 'supervisorForm.fields', 'assignments.students.studentSectionMemberships', 'assignments.course' => fn ($q) => $q->withoutGlobalScope('assigned_data'), 'assignments.evaluatee' => fn ($q) => $q->withoutGlobalScope('assigned_data'), 'assignments.supervisorEvaluators' => fn ($q) => $q->withoutGlobalScope('assigned_data'), 'assignments.evaluationForm']);
        $errors = [];
        if (! $cycle->studentForm || ($cycle->studentForm->settings['annex'] ?? null) !== 'A' || ! $cycle->studentForm->fields->contains(fn ($field) => in_array($field->type, ['rating', 'likert_scale'], true))) {
            $errors[] = 'Choose a complete Annex A instrument with rating criteria.';
        }
        if (! $cycle->supervisorForm || ! $cycle->supervisorForm->isSupervisorInstrument() || ! $cycle->supervisorForm->fields->contains(fn ($field) => in_array($field->type, ['rating', 'likert_scale'], true))) {
            $errors[] = 'Choose a complete Annex B instrument with rating criteria.';
        }
        $allHeads = $cycle->assignments->whereNotNull('course_id')->flatMap(fn ($class) => $class->supervisorEvaluators)->unique('id');
        foreach ($allHeads as $head) {
            if ($head->isStudent() || ! $head->roles()->exists() || ! $head->campuses()->whereKey($cycle->campus_id)->exists()) {
                $errors[] = 'An assigned supervisor has no valid staff membership in this campus.';
            }
        }
        $classes = $cycle->assignments->filter(fn ($a) => ! $a->evaluationForm?->isSupervisorInstrument());
        if ($classes->isEmpty()) {
            $errors[] = 'Select at least one teaching class.';
        }
        foreach ($classes as $class) {
            if (app(EvaluationSections::class)->requiresSection($class)) {
                $pending = $class->students->filter(fn ($student) => ! app(EvaluationSections::class)->forStudent($class, $student, true))->count();
                if ($pending) {
                    $errors[] = $class->display_name.': '.$pending.' students require sectioning because this subject has multiple instructors. Use Section students on Teaching Classes.';
                }
            }
            if ($class->students->isEmpty()) {
                $errors[] = 'Assign evaluators to '.$class->display_name.'.';
            }
            if (! $class->supervisorEvaluators->contains(fn ($head) => $head->id !== $class->user_id && ! $head->isStudent() && $head->roles()->exists() && $head->campuses()->whereKey($cycle->campus_id)->exists())) {
                $errors[] = 'Select a supervisor evaluator other than the faculty evaluated in '.$class->display_name.'. '
                    .((int) $cycle->department?->supervisor_id === (int) $class->user_id
                        ? 'The department supervisor teaches this class and cannot evaluate themselves. Choose another supervisor under Teaching Classes.'
                        : 'Use Department Supervisor to fill missing choices in bulk, or select supervisors under Teaching Classes.');
            }
            if ($class->formSubmissions()->exists() && $class->form_id !== $cycle->student_form_id) {
                $errors[] = 'Existing responses in '.$class->display_name.' require retaining the original instrument.';
            }
        }
        if ($cycle->starts_at && $cycle->ends_at && $cycle->ends_at->lte($cycle->starts_at)) {
            $errors[] = 'The closing date must be after the opening date.';
        }

        return ['errors' => array_unique($errors), 'classes' => $classes->count(), 'students' => $classes->sum(fn ($a) => $a->students->count()),
            'unique_students' => $classes->flatMap(fn ($a) => $a->students->pluck('id'))->unique()->count(), 'heads' => $allHeads->count()];
    }

    public function publish(User $actor, EvaluationCycle $cycle): void
    {
        $cycle->loadMissing('campus');
        abort_unless($this->allowed($actor, $cycle->campus) && $this->query($actor, $cycle->campus)->whereKey($cycle->id)->exists(), 403);
        DB::transaction(function () use ($actor, $cycle) {
            $cycle = EvaluationCycle::lockForUpdate()->findOrFail($cycle->id);
            abort_unless($cycle->status === 'draft', 409);
            $readiness = $this->readiness($cycle);
            if ($readiness['errors']) {
                throw ValidationException::withMessages(['readiness' => implode(' ', $readiness['errors'])]);
            }
            foreach ([$cycle->studentForm, $cycle->supervisorForm] as $form) {
                $form->update(['is_active' => true, 'published_at' => $form->published_at ?? now()]);
            }
            $cycle->update(['status' => 'published', 'published_at' => now(), 'published_by' => $actor->id]);
            $cycle->assignments()->whereHas('course', fn ($q) => $q->withoutGlobalScope('assigned_data'))->update(['form_id' => $cycle->student_form_id, 'evaluation_type' => 'SET', 'is_active' => true, 'teaching_department_id' => $cycle->department_id]);
            $heads = $cycle->assignments()->whereNotNull('course_id')->with('supervisorEvaluators')->get()->flatMap(fn ($class) => $class->supervisorEvaluators)->unique('id');
            foreach ($heads as $head) {
                app(SupervisorEvaluationAssignments::class)->prepare($head, $cycle->campus, $cycle);
            }
        });
    }

    public function close(User $actor, EvaluationCycle $cycle, bool $archive = false): void
    {
        $cycle->loadMissing('campus');
        abort_unless($this->allowed($actor, $cycle->campus) && $this->query($actor, $cycle->campus)->whereKey($cycle->id)->exists(), 403);
        if ($archive && $cycle->status !== 'closed') {
            abort(409, 'Close the cycle before archiving.');
        }
        if (! $archive && $cycle->status !== 'published') {
            abort(409, 'Only a published cycle can be closed.');
        }
        $cycle->update($archive ? ['status' => 'archived', 'archived_at' => now()] : ['status' => 'closed', 'closed_at' => now()]);
    }
}
