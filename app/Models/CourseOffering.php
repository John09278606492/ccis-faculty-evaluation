<?php

namespace App\Models;

use App\Support\EvaluationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class CourseOffering extends Model
{
    use HasFactory;

    protected $fillable = [
        'semester_id',
        'course_id',
        'user_id', // The person being evaluated (evaluatee)
        'evaluatee_label', // Display label: "Faculty", "Staff", "Dean", etc.
        'section',
        'is_active',
        'form_id', // Evaluation form assigned to this course offering
        'evaluation_type',
        'evaluation_cycle_id',
        'teaching_department_id',
        'evaluator_selection',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'evaluator_selection' => 'array',
    ];

    public function isEvaluationAvailable(): bool
    {
        $this->loadMissing('evaluationCycle', 'evaluationForm');
        if ($this->evaluation_cycle_id) {
            $cycle = $this->evaluationCycle;

            return $this->is_active && $cycle && $cycle->status === 'published'
                && (! $cycle->starts_at || ! $cycle->starts_at->isFuture())
                && (! $cycle->ends_at || ! $cycle->ends_at->isPast())
                && $this->evaluationForm && $this->evaluationForm->is_active && ! $this->evaluationForm->archived_at;
        }

        return $this->is_active && (bool) $this->evaluationForm?->isAvailable();
    }

    protected static function booted(): void
    {
        static::deleting(function (self $offering) {
            if ($offering->formSubmissions()->exists() || $offering->quizzes()->exists()) {
                throw ValidationException::withMessages(['assignment' => 'This class has evaluation or assessment records. Archive it instead of deleting it.']);
            }
        });
    }

    public function getEvaluationTypeCodeAttribute(): string
    {
        $this->loadMissing('evaluationForm');

        if ($this->evaluationForm?->isSupervisorInstrument()) {
            return 'SEF';
        }

        return EvaluationType::resolve($this->evaluation_type, $this->evaluationForm?->settings['instrument'] ?? null, $this->evaluationForm?->evaluator_roles ?? []);
    }

    public function getEvaluationTypeLabelAttribute(): string
    {
        return EvaluationType::LABELS[$this->evaluation_type_code];
    }

    /**
     * Get the evaluation form assigned to this course offering.
     */
    public function evaluationForm(): BelongsTo
    {
        return $this->belongsTo(Form::class, 'form_id');
    }

    public function evaluationCycle(): BelongsTo
    {
        return $this->belongsTo(EvaluationCycle::class);
    }

    public function teachingDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'teaching_department_id');
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
     * Get the evaluatee (person being evaluated) assigned to this course offering.
     */
    public function evaluatee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Alias for evaluatee() — backward compatibility.
     */
    public function instructor(): BelongsTo
    {
        return $this->evaluatee();
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'course_offering_student', 'course_offering_id', 'user_id')
            ->using(ClassroomEnrollment::class)
            ->withPivot('enrolled_at')
            ->withTimestamps();
    }

    public function supervisorEvaluators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'course_offering_supervisor')->withoutGlobalScope('assigned_data')->withTimestamps();
    }

    /** Reporting keeps the original roster even after a student account is deleted. */
    public function historicalStudents(): BelongsToMany
    {
        return $this->students()->withTrashed();
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    /**
     * Get the quizzes/exams/activities assigned to this course offering.
     */
    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class);
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

    public function getEvaluationSectionLabelAttribute(): string
    {
        if (($this->evaluator_selection['source'] ?? null) === 'sections_v1') {
            $sections = collect($this->evaluator_selection['sections'] ?? [])
                ->map(fn ($key) => rawurldecode(explode('~', $key, 3)[2] ?? ''))->filter()->unique();
            if ($sections->isNotEmpty()) {
                return $sections->join(', ');
            }
        }
        return $this->section ?: '—';
    }
}
