<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Semester extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_year_id',
        'name',
        'start_date',
        'end_date',
        'is_active',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    /**
     * Get the campus through the school year relationship for tenancy.
     */
    public function campus(): HasOneThrough
    {
        return $this->hasOneThrough(
            Campus::class,
            SchoolYear::class,
            'id', // Foreign key on school_years table
            'id', // Foreign key on campuses table
            'school_year_id', // Local key on semesters table
            'campus_id' // Local key on school_years table
        );
    }

    public function courseOfferings(): HasMany
    {
        return $this->hasMany(CourseOffering::class);
    }

    /**
     * Get the campus through the school year relationship.
     */
    public function getCampusAttribute()
    {
        return $this->schoolYear?->campus;
    }
}
