<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureCampusAccess Middleware
 *
 * Provides campus context to all requests for the authenticated user.
 * Sets request attributes and view-shared variables so downstream code can use them.
 *
 * Note: This middleware is an information provider, NOT an access enforcer.
 * Actual campus access enforcement is handled by:
 *   - Filament's multi-tenancy (User::canAccessTenant())
 *   - Resource-level query scoping in each Filament resource
 */
class EnsureCampusAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $user = Auth::user();

            // Store current user's campus context in request attributes
            $request->attributes->set('campus_id', $user->campus_id ?? null);
            $request->attributes->set('is_super_admin', $user->isSuperAdmin());

            // Share user campus info with views
            view()->share('userCampus', $user->campus ?? null);
            view()->share('userCampusId', $user->campus_id ?? null);
            view()->share('isSuperAdmin', $user->isSuperAdmin());
        }

        return $next($request);
    }
}
