<?php

namespace App\Policies;

use App\Models\FormSubmission;
use App\Models\User;
use App\Services\AssignedDataAccess;

class FormSubmissionPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isStudent() && ($user->can('view_form') || $user->can('page_EvaluationResults'));
    }

    public function view(User $user, FormSubmission $submission): bool
    {
        $submission->loadMissing('courseOffering.semester.schoolYear.campus');
        $campus = $submission->courseOffering?->campus;

        return $this->viewAny($user) && $campus && app(AssignedDataAccess::class)->apply(FormSubmission::query(), $user, $campus)->whereKey($submission->id)->exists();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, FormSubmission $submission): bool
    {
        return false;
    }

    public function delete(User $user, FormSubmission $submission): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, FormSubmission $submission): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
