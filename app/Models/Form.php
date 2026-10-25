<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Form extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_active',
        'requires_login',
        'evaluator_roles',
        'rating_scale',
        'scoring_config',
        'field_columns',
        'starts_at',
        'ends_at',
        'max_submissions',
        'settings',
        'previous_version_id', 'version', 'published_at', 'archived_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'published_at' => 'datetime', 'archived_at' => 'datetime',
        'requires_login' => 'boolean',
        'evaluator_roles' => 'array',
        'rating_scale' => 'array',
        'scoring_config' => 'array',
        'field_columns' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'settings' => 'array',
    ];

    public function isSupervisorInstrument(): bool
    {
        return ($this->settings['annex'] ?? null) === 'B';
    }

    public function isLocked(): bool
    {
        return $this->published_at !== null || $this->getRawOriginal('published_at') !== null || $this->submissions()->exists();
    }

    public function assertEditable(): void
    {
        if ($this->isLocked()) {
            throw ValidationException::withMessages(['name' => 'This instrument is published or already has responses. Duplicate it as a new version to edit its criteria or scoring.']);
        }
    }

    protected static function boot(): void
    {
        parent::boot();

        static::updating(function (Form $form) {
            if ($form->isDirty('published_at') && $form->getRawOriginal('published_at') !== null) {
                throw ValidationException::withMessages(['published_at' => 'A published instrument cannot be unlocked. Duplicate it as a new draft version.']);
            }
            if ($form->isDirty(['name', 'description', 'rating_scale', 'scoring_config', 'field_columns', 'settings', 'evaluator_roles'])) {
                $form->assertEditable();
            }
        });
        static::deleting(function (Form $form) {
            $form->assertEditable();
            if (CourseOffering::where('form_id', $form->id)->exists()) {
                throw ValidationException::withMessages(['form' => 'This instrument is assigned to a class. Archive it instead of deleting it.']);
            }
        });

        static::creating(function (Form $form) {
            if (empty($form->slug)) {
                $form->slug = Str::slug($form->name).'-'.Str::random(6);
            }
        });
    }

    /**
     * Get the campuses this form is assigned to.
     */
    public function campuses(): BelongsToMany
    {
        return $this->belongsToMany(Campus::class, 'campus_form')
            ->withTimestamps();
    }

    /**
     * Get the departments this form is assigned to.
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_form')
            ->withTimestamps();
    }

    /**
     * Get the fields for this form.
     */
    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class)->orderBy('order');
    }

    /**
     * Get the submissions for this form.
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    /**
     * Check if the form is currently available for submission.
     *
     * Tip: When checking many forms, use Form::withCount('submissions') to avoid N+1.
     * The method will automatically use the eager-loaded `submissions_count` if available.
     */
    public function isAvailable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();

        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }

        if ($this->ends_at && $now->gt($this->ends_at)) {
            return false;
        }

        if ($this->max_submissions) {
            // Use eager-loaded count if available to avoid N+1 queries
            $count = $this->submissions_count ?? $this->submissions()->count();
            if ($count >= $this->max_submissions) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get a summary of submission count.
     *
     * For list views, prefer using Form::withCount('submissions') in your query,
     * which provides $form->submissions_count automatically without N+1 queries.
     */
    public function getSubmissionCountAttribute(): int
    {
        // Use eager-loaded count if available (set by withCount('submissions'))
        if (array_key_exists('submissions_count', $this->attributes)) {
            return (int) $this->attributes['submissions_count'];
        }

        return $this->submissions()->count();
    }
}
