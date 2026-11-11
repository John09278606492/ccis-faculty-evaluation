<?php

namespace App\Policies;

use App\Models\AdvisoryTask;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AdvisoryTaskPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_advisory::task');
    }

    public function view(User $user, AdvisoryTask $task): bool
    {
        return $user->can('view_advisory::task') && $this->advises($user, $task);
    }

    public function create(User $user): bool
    {
        return $user->can('create_advisory::task');
    }

    public function update(User $user, AdvisoryTask $task): bool
    {
        return $user->can('update_advisory::task') && $this->advises($user, $task);
    }

    public function delete(User $user, AdvisoryTask $task): bool
    {
        return $user->can('delete_advisory::task') && $this->advises($user, $task);
    }

    private function advises(User $user, AdvisoryTask $task): bool
    {
        // Lists load the advisory class up front, so checking every row does not query per row.
        $adviser = $task->relationLoaded('advisoryClass') ? $task->advisoryClass?->adviser_id : $task->advisoryClass()->value('adviser_id');

        return $adviser === $user->id || $user->hasRole('super_admin');
    }
}
