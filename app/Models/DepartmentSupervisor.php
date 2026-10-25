<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class DepartmentSupervisor extends Pivot
{
    protected $touches = ['department'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    protected static function booted(): void
    {
        static::saved(fn () => \App\Services\ClassroomUpdates::panel('setup'));
        static::deleted(fn () => \App\Services\ClassroomUpdates::panel('setup'));
        static::saving(function (self $assignment) {
            $department = Department::withoutGlobalScope('assigned_data')->findOrFail($assignment->department_id);
            $valid = User::withoutGlobalScope('assigned_data')->whereKey($assignment->user_id)
                ->where('is_student', false)->whereHas('roles')
                ->whereHas('campuses', fn ($q) => $q->whereKey($department->campus_id))->exists();
            if (! $valid) {
                throw ValidationException::withMessages(['additionalSupervisors' => 'Choose an active staff account belonging to this campus.']);
            }
        });
    }
}
