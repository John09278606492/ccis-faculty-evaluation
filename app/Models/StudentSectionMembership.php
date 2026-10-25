<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentSectionMembership extends Model
{
    protected $fillable = ['user_id', 'department_id', 'semester_id', 'section', 'academic_section_id'];

    protected static function booted(): void
    {
        static::saving(function (self $membership) {
            $section = AcademicSection::withoutGlobalScope('assigned_data')->firstOrCreate([
                'department_id' => $membership->department_id, 'semester_id' => $membership->semester_id, 'name' => $membership->section,
            ], ['is_active' => true]);
            $membership->academic_section_id = $section->id;
        });
    }

    public function academicSection(): BelongsTo
    {
        return $this->belongsTo(AcademicSection::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }
}
