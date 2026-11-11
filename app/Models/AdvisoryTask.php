<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdvisoryTask extends Model
{
    public const STATUSES = [
        'open' => 'Open: students can submit',
        'closed' => 'Closed: visible but no more submissions',
        'archived' => 'Archived: hidden from students',
    ];

    public const DEFAULT_CONSEQUENCE = 'Failure to submit on or before the due date means you will not be enrolled.';

    protected $fillable = ['advisory_class_id', 'advising_semester_id', 'title', 'instructions', 'requirements', 'due_at', 'consequence', 'allow_late', 'status', 'created_by'];

    protected $casts = ['requirements' => 'array', 'due_at' => 'datetime', 'allow_late' => 'boolean'];

    public function advisoryClass(): BelongsTo
    {
        return $this->belongsTo(AdvisoryClass::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AdvisorySubmission::class);
    }

    public function advisingSemester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'advising_semester_id')->withoutGlobalScope('assigned_data');
    }

    public function getAdvisingTermAttribute(): string
    {
        return $this->advisingSemester
            ? $this->advisingSemester->name.' · '.$this->advisingSemester->schoolYear?->name
            : 'Not set';
    }

    public function isPastDue(): bool
    {
        return $this->due_at !== null && $this->due_at->isPast();
    }

    /** Whether a student can still send or replace their files. */
    public function acceptsSubmissions(): bool
    {
        return $this->status === 'open' && ($this->allow_late || ! $this->isPastDue());
    }

    /** @return array<int, string> */
    public function requirementList(): array
    {
        return array_values(array_filter(array_map(fn ($item) => trim((string) $item), $this->requirements ?? [])));
    }
}
