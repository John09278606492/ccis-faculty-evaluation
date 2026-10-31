<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentAcademicRecord extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['reported_year_level' => 'integer', 'total_units' => 'decimal:2'];
}
