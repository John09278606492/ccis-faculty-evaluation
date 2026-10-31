<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeachingClassSourceLink extends Model
{
    protected $guarded = [];

    protected $casts = ['metadata' => 'array'];
}
