<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;

/**
 * CampusScoped Trait
 *
 * This trait automatically scopes queries to the current user's campus.
 * Super admins can access all campuses' data.
 * Regular admins are restricted to their own campus.
 */
trait CampusScoped
{
    /**
     * Boot the trait.
     */
    public static function bootCampusScoped()
    {
        // Auto-fill campus_id on create for non-super-admin users
        static::creating(function ($model) {
            if (Auth::check() && ! isset($model->campus_id) && $model->hasAttribute('campus_id')) {
                $user = Auth::user();
                // Only auto-fill for non-super-admin users
                if ($user->campus_id && ! $user->isSuperAdmin()) {
                    $model->campus_id = $user->campus_id;
                }
            }
        });

        // Scope queries to current user's campus (unless they are super_admin)
        static::addGlobalScope('campus', function ($query) {
            if (Auth::check()) {
                $user = Auth::user();

                // Super admin can access everything (no campus restriction)
                if (! $user->isSuperAdmin()) {
                    // Regular admins can only access their campus
                    if ($user->campus_id) {
                        $query->where(function ($q) use ($user) {
                            $q->where($model->getTable().'.campus_id', $user->campus_id)
                                ->orWhereNull($model->getTable().'.campus_id');
                        });
                    } else {
                        // User without campus can only see records without campus assignment
                        $query->whereNull($model->getTable().'.campus_id');
                    }
                }
            } else {
                // For unauthenticated users, only show records without campus assignment
                $query->whereNull($model->getTable().'.campus_id');
            }
        });
    }

    /**
     * Get the campus that owns this model.
     */
    public function campus()
    {
        return $this->belongsTo(config('laravel-tenancy.models.campus', 'App\\Models\\Campus'));
    }
}
