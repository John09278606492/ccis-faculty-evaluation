<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/** A section under an adviser. Its students are whoever belongs to the section. */
class AdvisoryClass extends Model
{
    protected $fillable = ['academic_section_id', 'adviser_id', 'created_by'];

    public function section(): BelongsTo
    {
        return $this->belongsTo(AcademicSection::class, 'academic_section_id')->withoutGlobalScope('assigned_data');
    }

    public function adviser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adviser_id')->withoutGlobalScope('assigned_data')->withTrashed();
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(AdvisoryTask::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'student_section_memberships', 'academic_section_id', 'user_id', 'academic_section_id', 'id')
            ->withoutGlobalScope('assigned_data');
    }

    public function scopeInCampus(Builder $query, ?int $campusId): Builder
    {
        return $query->whereIn('academic_section_id', static::sectionIdsIn($campusId));
    }

    /** Sections of a campus, without the staff data scope: an adviser need not teach the section. */
    public static function sectionIdsIn(?int $campusId): \Illuminate\Database\Query\Builder
    {
        return DB::table('academic_sections as section')
            ->join('semesters as semester', 'semester.id', '=', 'section.semester_id')
            ->join('school_years as year', 'year.id', '=', 'semester.school_year_id')
            ->where('year.campus_id', $campusId)
            ->select('section.id');
    }

    public function getNameAttribute(): string
    {
        return (string) $this->section?->name;
    }
}
