<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkspaceDraft extends Model
{
    protected $guarded = [];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'expires_at' => 'datetime'];
    }
}
