<?php

namespace App\Http\Middleware;

use App\Services\AssignedDataAccess;
use App\Services\UserAccessAdministration;
use Closure;
use Illuminate\Http\Request;

class ScopeAssignedData
{
    public function handle(Request $request, Closure $next)
    {
        $scope = app(AssignedDataAccess::class);
        $previous = $scope->actor;
        $request->user()?->loadMissing('roles.permissions', 'permissions');
        $actor = $request->user();
        if ($actor?->isStudent()) {
            $unexpected = $actor->getAllPermissions()->pluck('name')->diff(app(UserAccessAdministration::class)->studentPermissionNames());
            abort_if($unexpected->isNotEmpty(), 403, 'This student account has incompatible staff permissions. Ask an administrator to correct its profile and roles.');
        }
        $scope->actor = $request->user();
        try {
            return $next($request);
        } finally {
            // Livewire runs the original route's middleware before component
            // hydration/rendering. Retain this request-scoped actor until that
            // rendering finishes; the scoped container resets it next request.
            if (! request()->hasHeader('X-Livewire')) {
                $scope->actor = $previous;
            }
        }
    }
}
