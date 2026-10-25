<?php

namespace App\Services;

use App\Models\CourseOffering;
use App\Models\Form;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class EvaluationAccessService
{
    /**
     * @throws AuthorizationException
     */
    public function authorize(CourseOffering $courseOffering, ?User $user, bool $includeClosed = false): Form
    {
        if ($user && ($user->trashed() || ! User::withoutGlobalScope('assigned_data')->whereKey($user->id)->exists())) {
            throw new AuthorizationException('This account is no longer active.');
        }
        $courseOffering->loadMissing([
            'evaluationForm.campuses',
            'evaluationCycle',
            'semester.schoolYear',
            'course',
            'evaluatee' => fn ($q) => $q->withoutGlobalScope('assigned_data'),
            'evaluatee.instructorDepartment',
        ]);
        $form = $courseOffering->evaluationForm;
        $form?->loadMissing(['departments' => fn ($q) => $q->withoutGlobalScope('assigned_data')]);
        if (! $includeClosed && $courseOffering->evaluation_cycle_id && $courseOffering->evaluationCycle?->status !== 'published') {
            throw new AuthorizationException('This evaluation cycle is not open.');
        }

        if (! $courseOffering->is_active || ! $form) {
            throw new AuthorizationException('This course offering is not available for evaluation.');
        }

        $campusId = $courseOffering->semester?->schoolYear?->campus_id;
        if (! $campusId || ! $form->campuses->contains('id', $campusId)) {
            throw new AuthorizationException('This evaluation form is not assigned to the course offering campus.');
        }

        $departmentId = $courseOffering->teaching_department_id ?? $courseOffering->course?->department_id
            ?? $courseOffering->evaluatee?->instructor_department_id;
        if ($form->departments->isNotEmpty()
            && (! $departmentId || ! $form->departments->contains('id', $departmentId))) {
            throw new AuthorizationException('This evaluation form is not assigned to the course offering department.');
        }

        if ($form->isSupervisorInstrument() && (! $user || $user->isStudent())) {
            throw new AuthorizationException('Annex B must be completed by a signed-in supervisor.');
        }

        if ($form->isSupervisorInstrument()) {
            $assigned = app(FacultySupervisorEvaluators::class)->taskIds($user, $courseOffering->campus)->where('task.id', $courseOffering->id)->exists();
            if (! $assigned || $courseOffering->user_id === $user->id
                || ! $user->campuses()->whereKey($campusId)->exists()) {
                throw new AuthorizationException('Only supervisor evaluators explicitly assigned to this faculty may complete Annex B. Self-evaluation is not allowed.');
            }

            return $form;
        }

        if (! $user) {
            if ($form->requires_login) {
                throw new AuthorizationException('You must log in to access this evaluation form.');
            }

            return $form;
        }

        if ($user->isSuperAdmin()) {
            return $form;
        }

        if (! $user->campuses()->whereKey($campusId)->exists()) {
            throw new AuthorizationException('You do not have access to this campus.');
        }

        if ($user->isStudent() && ! $courseOffering->students()->whereKey($user->getKey())->exists()) {
            throw new AuthorizationException('You are not enrolled in this course offering.');
        }

        $allowedRoles = $form->evaluator_roles ?? [];
        if ($allowedRoles !== [] && $user->getRoleNames()->intersect($allowedRoles)->isEmpty()) {
            throw new AuthorizationException('Your role is not allowed to submit this evaluation.');
        }

        return $form;
    }
}
