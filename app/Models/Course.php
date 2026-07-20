<?php

namespace App\Models;

use App\Traits\NormalizesTextFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Course extends Model
{
    use HasFactory, NormalizesTextFields;

    protected $fillable = [
        'department_id',
        'code',
        'name',
        'description',
        'units',
        'is_active',
    ];

    protected $casts = [
        'units' => 'decimal:1',
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
            'department_id', // Local key on courses table
            'campus_id' // Local key on departments table
        );
    }

    public function courseOfferings(): HasMany
    {
        return $this->hasMany(CourseOffering::class);
    }

    /**
     * Get the campus through the department relationship.
     */
    public function getCampusAttribute()
    {
        return $this->department?->campus;
    }
}
