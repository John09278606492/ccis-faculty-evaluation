<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;

trait CampusScoped
{
    /**
     * Boot the trait.
     */
    public static function bootCampusScoped()
    {
        // Auto-fill campus_id on create for users
        static::creating(function ($model) {
            if (Auth::check() && !isset($model->campus_id) && $model->hasAttribute('campus_id')) {
                $model->campus_id = Auth::user()->campus_id;
            }
        });

        // Scope queries to current user's campus (unless they are super_admin)
        static::addGlobalScope('campus', function ($query) {
            if (Auth::check()) {
                // Super admin can access everything (no campus restriction)
                if (!Auth::user()->hasRole('super_admin')) {
                    // Regular admins can only access their campus
                    if (Auth::user()->campus_id) {
                        $query->where('campus_id', Auth::user()->campus_id);
                    }
                }
            }
        });
    }
}

