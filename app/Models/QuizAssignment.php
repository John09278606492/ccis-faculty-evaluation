<?php

namespace App\Models;

use App\Services\ClassroomUpdates;
use Illuminate\Database\Eloquent\Relations\Pivot;

class QuizAssignment extends Pivot
{
    protected $table = 'quiz_student';

    protected static function booted(): void
    {
        static::saved(fn (self $row) => ClassroomUpdates::quiz($row->quiz_id, 'assignment'));
        static::deleted(fn (self $row) => ClassroomUpdates::quiz($row->quiz_id, 'assignment'));
    }
}
