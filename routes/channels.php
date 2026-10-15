<?php

use App\Models\Campus;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('{environment}.campus.{campusId}', function (User $user, string $environment, int $campusId) {
    $campus = Campus::find($campusId);

    return $environment === app()->environment() && $campus && $user->canAccessTenant($campus);
});
