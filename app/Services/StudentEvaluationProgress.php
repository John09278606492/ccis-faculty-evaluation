<?php

namespace App\Services;

use App\Models\AcademicSection;
use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class StudentEvaluationProgress
{
    private function emptyProgress(): array
    {
        return ['completed' => 0, 'total' => 0, 'remaining' => 0, 'open_pending' => 0, 'not_open' => 0, 'closed_pending' => 0, 'unavailable' => 0];
    }

    private function pendingState(CourseOffering $assignment): string
    {
        $form = $assignment->evaluationForm;
        if (($assignment->evaluationCycle && in_array($assignment->evaluationCycle->status, ['closed', 'archived'], true)) || ($assignment->evaluationCycle?->ends_at && $assignment->evaluationCycle->ends_at->isPast()) || ! $form->is_active || ($form->ends_at && $form->ends_at->isPast())) {
            return 'closed_pending';
        }
        if (($assignment->evaluationCycle && $assignment->evaluationCycle->status === 'draft') || ($assignment->evaluationCycle?->starts_at && $assignment->evaluationCycle->starts_at->isFuture()) || ($form->starts_at && $form->starts_at->isFuture())) {
            return 'not_open';
        }

        return $assignment->isEvaluationAvailable() ? 'open_pending' : 'unavailable';
    }

    /** Completion-only section totals; identities are loaded in batches, never response contents. */
    public function sectionSummaries(User $actor, Campus $campus, array $sectionIds, ?array $departmentIds = null): array
    {
        abort_unless(! $actor->isStudent() && $actor->canAccessTenant($campus) && $actor->can('page_StudentEvaluationProgress'), 403);
        $allowed = $this->departmentIds($actor, $campus);
        $departmentIds = $departmentIds === null ? $allowed : ($allowed === null ? $departmentIds : array_values(array_intersect($departmentIds, $allowed)));
        $sections = app(AssignedDataAccess::class)->apply(AcademicSection::query(), $actor, $campus)->whereKey($sectionIds)
            ->when($departmentIds !== null, fn ($q) => $q->whereIn('department_id', $departmentIds))->get();
        if ($sections->isEmpty()) {
            return [];
        }
        $memberships = DB::table('student_section_memberships')->whereIn('academic_section_id', $sections->modelKeys())->get(['academic_section_id', 'user_id']);
        $studentQuery = app(AcademicStudents::class)->students($actor, $campus)
            ->whereHas('campuses', fn ($q) => $q->whereKey($campus->id))
            ->whereIn('users.id', DB::table('student_section_memberships')->whereIn('academic_section_id', $sections->modelKeys())->select('user_id'));
        $students = (clone $studentQuery)->with('roles')->get()->keyBy('id');
        $perStudent = [];
        $assignments = $this->scopeAssignments(CourseOffering::query(), $campus, $departmentIds)
            ->whereIn('semester_id', $sections->pluck('semester_id')->unique())
            ->whereHas('students', fn ($q) => $q->whereIn('users.id', (clone $studentQuery)->select('users.id')))
            ->with(['evaluationForm' => fn ($q) => $q->withCount('submissions'), 'evaluationForm.campuses', 'evaluationForm.departments',
                'course', 'evaluationCycle', 'semester.schoolYear', 'evaluatee.instructorDepartment'])->get();
        $enrolments = $completedUsers = $completedFingerprints = [];
        foreach ($assignments->modelKeys() ? array_chunk($assignments->modelKeys(), 400) : [] as $ids) {
            foreach (DB::table('course_offering_student')->whereIn('course_offering_id', $ids)->whereIn('user_id', (clone $studentQuery)->select('users.id'))->get(['course_offering_id', 'user_id']) as $enrolment) {
                $enrolments[$enrolment->course_offering_id][$enrolment->user_id] = true;
            }
            foreach (FormSubmission::whereIn('course_offering_id', $ids)->get(['course_offering_id', 'form_id', 'user_id', 'submission_fingerprint']) as $submission) {
                if ($submission->user_id) {
                    $completedUsers[$submission->course_offering_id][$submission->form_id][$submission->user_id] = true;
                }
                if ($submission->submission_fingerprint) {
                    $completedFingerprints[$submission->course_offering_id][$submission->form_id][$submission->submission_fingerprint] = true;
                }
            }
        }
        foreach ($assignments as $assignment) {
            $form = $assignment->evaluationForm;
            $department = $assignment->teaching_department_id ?? $assignment->course?->department_id ?? $assignment->evaluatee?->instructor_department_id;
            // Match the form/campus/department/role rules used by EvaluationAccessService.
            // Fresh scoped users and enrollment pivots above already establish active campus membership and class enrollment.
            if (! $form || $form->isSupervisorInstrument() || ! $form->campuses->contains('id', $campus->id)
                || ($form->departments->isNotEmpty() && ! $form->departments->contains('id', $department))) {
                continue;
            }
            $state = $this->pendingState($assignment);
            foreach (array_keys($enrolments[$assignment->id] ?? []) as $id) {
                $student = $students[$id];
                if (! $student->isSuperAdmin() && ($form->evaluator_roles ?? []) !== [] && $student->getRoleNames()->intersect($form->evaluator_roles)->isEmpty()) {
                    continue;
                }
                $perStudent[$id][$assignment->semester_id] ??= $this->emptyProgress();
                $progress = &$perStudent[$id][$assignment->semester_id];
                $progress['total']++;
                $fingerprint = app(EvaluationSubmissionIdentity::class)->fingerprint($assignment, request(), $student);
                if (isset($completedUsers[$assignment->id][$form->id][$id]) || isset($completedFingerprints[$assignment->id][$form->id][$fingerprint])) {
                    $progress['completed']++;
                } else {
                    $progress['remaining']++;
                    $progress[$state]++;
                }
                unset($progress);
            }
        }
        $result = [];
        foreach ($sections as $section) {
            $ids = $memberships->where('academic_section_id', $section->id)->pluck('user_id')->unique()->filter(fn ($id) => $students->has($id));
            $totals = ['students' => $ids->count(), 'assigned_students' => 0, 'complete_students' => 0, 'followup_students' => 0, 'unassigned_students' => 0,
                ...$this->emptyProgress()];
            foreach ($ids as $id) {
                $progress = $perStudent[$id][$section->semester_id] ?? $this->emptyProgress();
                $totals['assigned_students'] += (int) ($progress['total'] > 0);
                $totals['complete_students'] += (int) ($progress['total'] > 0 && $progress['remaining'] === 0);
                $totals['followup_students'] += (int) ($progress['open_pending'] > 0);
                $totals['unassigned_students'] += (int) ($progress['total'] === 0);
                foreach (array_keys($this->emptyProgress()) as $key) {
                    $totals[$key] += $progress[$key];
                }
            }
            $result[$section->id] = [...$totals, 'completion_rate' => $totals['total'] ? round($totals['completed'] / $totals['total'] * 100, 1) : null];
        }

        return $result;
    }

    /**
     * How many evaluations each given student can still submit right now (open, not yet done), for reminders.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, int> user id => open evaluations left (students with none are left out)
     */
    public function openPendingCounts(Campus $campus, array $userIds, ?int $semesterId = null, ?array $departmentIds = null): array
    {
        $counts = [];
        $students = User::whereKey($userIds)->with('roles')->get()->keyBy('id');
        $identity = app(EvaluationSubmissionIdentity::class);
        foreach (array_chunk($students->keys()->all(), 400) as $chunk) {
            $classes = $this->scopeAssignments(CourseOffering::query(), $campus, $departmentIds)
                ->when($semesterId, fn ($q) => $q->where('semester_id', $semesterId))
                ->whereHas('students', fn ($q) => $q->whereIn('users.id', $chunk))
                ->with(['evaluationForm.campuses', 'evaluationForm.departments', 'course', 'evaluationCycle', 'semester.schoolYear', 'evaluatee.instructorDepartment'])->get()
                ->filter(function (CourseOffering $class) use ($campus) {
                    $form = $class->evaluationForm;
                    $department = $class->teaching_department_id ?? $class->course?->department_id ?? $class->evaluatee?->instructor_department_id;

                    return $form && ! $form->isSupervisorInstrument() && $form->campuses->contains('id', $campus->id)
                        && ($form->departments->isEmpty() || $form->departments->contains('id', $department))
                        && $this->pendingState($class) === 'open_pending';
                })->keyBy('id');
            if ($classes->isEmpty()) {
                continue;
            }
            foreach (array_chunk($classes->keys()->all(), 300) as $ids) {
                $done = FormSubmission::whereIn('course_offering_id', $ids)->get(['course_offering_id', 'form_id', 'user_id', 'submission_fingerprint']);
                $fingerprints = $done->pluck('submission_fingerprint')->filter()->flip();
                $legacy = $done->filter(fn ($row) => $row->user_id && $row->form_id === $classes[$row->course_offering_id]->form_id)
                    ->mapWithKeys(fn ($row) => [$row->course_offering_id.':'.$row->user_id => true]);
                foreach (DB::table('course_offering_student')->whereIn('course_offering_id', $ids)->whereIn('user_id', $chunk)->get(['course_offering_id', 'user_id']) as $row) {
                    $class = $classes[$row->course_offering_id];
                    $student = $students[$row->user_id];
                    $roles = $class->evaluationForm->evaluator_roles ?? [];
                    if ($roles !== [] && ! $student->isSuperAdmin() && $student->getRoleNames()->intersect($roles)->isEmpty()) {
                        continue;
                    }
                    if (! $fingerprints->has($identity->forUser($class, $row->user_id)) && ! $legacy->has($row->course_offering_id.':'.$row->user_id)) {
                        $counts[$row->user_id] = ($counts[$row->user_id] ?? 0) + 1;
                    }
                }
            }
        }

        return $counts;
    }

    public function departmentIds(User $actor, Campus $campus): ?array
    {
        return app(AssignedDataAccess::class)->departmentIds($actor, $campus);
    }

    public function scopeAssignments(Builder $query, Campus $campus, ?array $departmentIds): Builder
    {
        return $query->where('is_active', true)->whereNotNull('form_id')
            ->whereHas('semester.schoolYear', fn ($q) => $q->where('campus_id', $campus->id))
            ->when($departmentIds !== null, fn ($q) => $q->where(function ($q) use ($departmentIds) {
                $q->whereIn('teaching_department_id', $departmentIds)->orWhere(function ($q) use ($departmentIds) {
                    $q->whereNull('teaching_department_id')->where(fn ($q) => $q->whereHas('course', fn ($q) => $q->whereIn('department_id', $departmentIds))
                        ->orWhere(fn ($q) => $q->whereNull('course_id')->whereHas('evaluatee', fn ($q) => $q->whereIn('instructor_department_id', $departmentIds))));
                });
            }));
    }

    public function counts(User $student, Campus $campus, ?int $semesterId = null, ?array $departmentIds = null): array
    {
        return array_intersect_key($this->summary($student, $campus, $semesterId, $departmentIds), array_flip(['completed', 'total', 'remaining']));
    }

    public function summary(User $student, Campus $campus, ?int $semesterId = null, ?array $departmentIds = null): array
    {
        $student->loadMissing('roles');
        $assignments = $this->scopeAssignments($student->courseOfferings()->getQuery()->select('course_offerings.*'), $campus, $departmentIds)
            ->when($semesterId, fn ($q) => $q->where('semester_id', $semesterId))
            ->whereHas('evaluationForm')
            ->with(['evaluationForm.campuses', 'evaluationForm.departments', 'course', 'evaluationCycle', 'semester.schoolYear', 'evaluatee.instructorDepartment',
                'formSubmissions' => fn ($q) => $q->select('id', 'course_offering_id', 'form_id', 'submission_fingerprint', 'user_id')])->get();
        $total = $completed = 0;
        $states = ['open_pending' => 0, 'not_open' => 0, 'closed_pending' => 0, 'unavailable' => 0];
        foreach ($assignments as $assignment) {
            try {
                app(EvaluationAccessService::class)->authorize($assignment, $student, true);
            } catch (AuthorizationException) {
                continue;
            }
            $total++;
            $fingerprint = app(EvaluationSubmissionIdentity::class)->fingerprint($assignment, request(), $student);
            if ($assignment->formSubmissions->contains(fn ($s) => $s->form_id === $assignment->form_id
                && ($s->submission_fingerprint === $fingerprint || $s->user_id === $student->id))) {
                $completed++;

                continue;
            }
            $states[$this->pendingState($assignment)]++;
        }

        return ['completed' => $completed, 'total' => $total, 'remaining' => $total - $completed, ...$states];
    }
}
