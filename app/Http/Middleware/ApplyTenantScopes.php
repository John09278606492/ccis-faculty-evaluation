<?php

namespace App\Http\Middleware;

use App\Models\Book;
use App\Models\Contact;
use App\Models\Post;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * ApplyTenantScopes Middleware
 * 
 * Automatically applies global scopes to models based on the current tenant.
 * This ensures that queries in tenant-aware resources are automatically scoped.
 */
class ApplyTenantScopes
{
    public function handle(Request $request, Closure $next)
    {
        if (Filament::getTenant()) {
            // Apply tenant scope to Post model
            Post::addGlobalScope('tenant', function (Builder $query) {
                $query->whereBelongsTo(Filament::getTenant(), 'campus');
            });

            // Apply tenant scope to Contact model
            Contact::addGlobalScope('tenant', function (Builder $query) {
                $query->whereBelongsTo(Filament::getTenant(), 'campus');
            });

            // Apply tenant scope to Book model
            Book::addGlobalScope('tenant', function (Builder $query) {
                $query->whereBelongsTo(Filament::getTenant(), 'campus');
            });
        }

        return $next($request);
    }
}
