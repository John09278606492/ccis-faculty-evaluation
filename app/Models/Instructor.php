<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Instructor extends Model
{
    use HasFactory;

    protected $fillable = [
        'department_id',
        'employee_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'position',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Get the campus through the department relationship for tenancy.
     */
    public function campus(): HasOneThrough
    {
        return $this->hasOneThrough(
            Campus::class,
            Department::class,
            'id', // Foreign key on departments table
            'id', // Foreign key on campuses table
            'department_id', // Local key on instructors table
            'campus_id' // Local key on departments table
        );
    }

    public function courseOfferings(): HasMany
    {
        return $this->hasMany(CourseOffering::class);
    }

    /**
     * Get the instructor's full name.
     */
    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * Get the campus through the department relationship.
     */
    public function getCampusAttribute()
    {
        return $this->department?->campus;
    }
}
