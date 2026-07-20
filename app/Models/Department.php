<?php

namespace App\Models;

use App\Traits\NormalizesTextFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasFactory, NormalizesTextFields;

    protected $fillable = [
        'campus_id',
        'name',
        'code',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /**
     * Get the instructors (users with is_instructor=true) for this department.
     * Uses the User model with instructor_department_id foreign key.
     */
    public function instructors(): HasMany
    {
        return $this->hasMany(User::class, 'instructor_department_id')
            ->where('is_instructor', true);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }
}
