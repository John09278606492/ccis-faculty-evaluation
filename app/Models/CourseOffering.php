<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Form;
use App\Models\FormSubmission;

class CourseOffering extends Model
{
    use HasFactory;

    protected $fillable = [
        'semester_id',
        'course_id',
        'user_id', // The instructor (user with is_instructor=true)
        'section',
        'is_active',
        'form_id', // Evaluation form assigned to this course offering
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the evaluation form assigned to this course offering.
     */
    public function evaluationForm(): BelongsTo
    {
        return $this->belongsTo(Form::class, 'form_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Get the instructor (user) assigned to this course offering.
     */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'course_offering_student', 'course_offering_id', 'user_id')
            ->withPivot('enrolled_at')
            ->withTimestamps();
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    /**
     * Get the evaluation form submissions for this course offering.
     */
    public function formSubmissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    /**
     * Get the campus through the semester relationship.
     */
    public function getCampusAttribute()
    {
        return $this->semester?->schoolYear?->campus;
    }

    /**
     * Get a display label for the course offering.
     */
    public function getDisplayNameAttribute(): string
    {
        $section = $this->section ? " - {$this->section}" : '';
        return "{$this->course?->code}{$section}";
    }
}
