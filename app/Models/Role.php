<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Role extends SpatieRole
{
    /**
     * Get the campus this role belongs to.
     * Global roles (like super_admin) have null team_id.
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class, 'team_id');
    }

    /**
     * Check if this is a global role (no campus assignment).
     */
    public function isGlobal(): bool
    {
        return is_null($this->team_id);
    }

    /**
     * Check if this is the super_admin role.
     */
    public function isSuperAdmin(): bool
    {
        return $this->name === 'super_admin';
    }
}
