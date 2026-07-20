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
    public function authorize(CourseOffering $courseOffering, ?User $user): Form
    {
        $courseOffering->loadMissing(
            'evaluationForm.campuses',
            'evaluationForm.departments',
            'semester.schoolYear',
            'course',
            'evaluatee',
        );
        $form = $courseOffering->evaluationForm;

        if (! $courseOffering->is_active || ! $form) {
            throw new AuthorizationException('This course offering is not available for evaluation.');
        }

        $campusId = $courseOffering->semester?->schoolYear?->campus_id;
        if (! $campusId || ! $form->campuses->contains('id', $campusId)) {
            throw new AuthorizationException('This evaluation form is not assigned to the course offering campus.');
        }

        $departmentId = $courseOffering->course?->department_id
            ?? $courseOffering->evaluatee?->instructor_department_id;
        if ($form->departments->isNotEmpty()
            && (! $departmentId || ! $form->departments->contains('id', $departmentId))) {
            throw new AuthorizationException('This evaluation form is not assigned to the course offering department.');
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
