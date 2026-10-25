<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentSubjectEnrolment extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['lecture' => 'decimal:2', 'laboratory' => 'decimal:2', 'units' => 'decimal:2'];
}
