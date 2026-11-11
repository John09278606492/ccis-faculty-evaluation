<?php

namespace App\Policies;

use App\Models\AdvisoryClass;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AdvisoryClassPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_advisory::class');
    }

    public function view(User $user, AdvisoryClass $class): bool
    {
        return $user->can('view_advisory::class') && $this->advises($user, $class);
    }

    public function create(User $user): bool
    {
        return $user->can('create_advisory::class');
    }

    public function update(User $user, AdvisoryClass $class): bool
    {
        return $user->can('update_advisory::class') && $this->advises($user, $class);
    }

    public function delete(User $user, AdvisoryClass $class): bool
    {
        return $user->can('delete_advisory::class') && $this->advises($user, $class);
    }

    private function advises(User $user, AdvisoryClass $class): bool
    {
        return $class->adviser_id === $user->id || $user->hasRole('super_admin');
    }
}
