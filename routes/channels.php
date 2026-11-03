<?php

use App\Models\Campus;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('{environment}.campus.{campusId}', function (User $user, string $environment, int $campusId) {
    $campus = Campus::find($campusId);

    return $environment === app()->environment() && $campus && $user->canAccessTenant($campus);
});

// Panel-wide "records changed" signals (invalidation only, no record data).
Broadcast::channel('{environment}.panel', fn (User $user, string $environment) => $environment === app()->environment());

Broadcast::channel('{environment}.panel.staff', fn (User $user, string $environment) => $environment === app()->environment() && ! $user->isStudent());

// Filament database notifications (the bell) arrive instantly instead of on the next poll.
Broadcast::channel('App.Models.User.{id}', fn (User $user, int $id) => $user->id === $id);
