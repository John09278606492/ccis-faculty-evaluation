<?php

namespace App\Policies;

use App\Models\AcknowledgementForm;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AcknowledgementFormPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_acknowledgement::form');
    }

    public function view(User $user, AcknowledgementForm $form): bool
    {
        return $user->can('view_acknowledgement::form') && $this->ownsClass($user, $form);
    }

    public function create(User $user): bool
    {
        return $user->can('create_acknowledgement::form');
    }

    public function update(User $user, AcknowledgementForm $form): bool
    {
        return $user->can('update_acknowledgement::form') && $this->ownsClass($user, $form);
    }

    public function delete(User $user, AcknowledgementForm $form): bool
    {
        return $user->can('delete_acknowledgement::form') && $this->ownsClass($user, $form);
    }

    private function ownsClass(User $user, AcknowledgementForm $form): bool
    {
        // Lists load the class up front, so checking every row does not query per row.
        $owner = $form->relationLoaded('courseOffering')
            ? $form->courseOffering?->user_id
            : $form->courseOffering()->withoutGlobalScope('assigned_data')->value('user_id');

        return $owner === $user->id || $user->hasRole('super_admin');
    }
}
