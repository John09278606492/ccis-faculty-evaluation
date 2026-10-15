<?php

namespace App\Models;

use App\Services\ClassroomUpdates;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ClassroomEnrollment extends Pivot
{
    protected $table = 'course_offering_student';

    protected static function booted(): void
    {
        static::saved(fn (self $row) => ClassroomUpdates::course($row->course_offering_id, 'enrollment'));
        static::deleted(fn (self $row) => ClassroomUpdates::course($row->course_offering_id, 'enrollment'));
    }
}
