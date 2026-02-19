<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureCampusAccess Middleware
 * 
 * Ensures that authenticated users can only access data from their assigned campus.
 * Super admins have access to all campuses.
 */
class EnsureCampusAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $user = Auth::user();
            
            // Store current user's campus in request context
            $request->attributes->set('campus_id', $user->campus_id);
            $request->attributes->set('is_super_admin', $user->isSuperAdmin());
            
            // Share user campus info with views
            view()->share('userCampus', $user->campus);
            view()->share('userCampusId', $user->campus_id);
            view()->share('isSuperAdmin', $user->isSuperAdmin());
        }
        
        return $next($request);
    }
}
