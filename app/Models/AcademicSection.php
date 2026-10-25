<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AcademicSection extends Model
{
    protected $fillable = ['department_id', 'semester_id', 'name', 'is_active'];

    protected $casts = ['department_id' => 'integer', 'semester_id' => 'integer', 'is_active' => 'boolean'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'student_section_memberships')->withPivot('department_id', 'semester_id', 'section')->withTimestamps();
    }
}
