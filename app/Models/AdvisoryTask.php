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

    /** File kinds an adviser can accept, with the content types the server detects for each. */
    public const FILE_TYPES = [
        'images' => ['label' => 'Screenshots and photos (JPG, PNG, WebP)', 'short' => 'JPG, PNG, WebP',
            'mimes' => ['image/jpeg', 'image/png', 'image/webp']],
        'pdf' => ['label' => 'PDF documents', 'short' => 'PDF',
            'mimes' => ['application/pdf']],
        'word' => ['label' => 'Word documents (DOC, DOCX)', 'short' => 'Word',
            'mimes' => ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']],
        'excel' => ['label' => 'Excel spreadsheets (XLS, XLSX)', 'short' => 'Excel',
            'mimes' => ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']],
        'powerpoint' => ['label' => 'PowerPoint presentations (PPT, PPTX)', 'short' => 'PowerPoint',
            'mimes' => ['application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation']],
    ];

    public const DEFAULT_FILE_TYPES = ['images', 'pdf'];

    protected $fillable = ['advisory_class_id', 'advising_semester_id', 'title', 'instructions', 'requirements', 'accepted_types', 'due_at', 'consequence', 'allow_late', 'status', 'created_by'];

    protected $casts = ['requirements' => 'array', 'accepted_types' => 'array', 'due_at' => 'datetime', 'allow_late' => 'boolean'];

    /** @return array<int, string> the chosen file kinds, or screenshots and PDF for tasks made before this choice existed */
    public function fileTypes(): array
    {
        $types = array_values(array_intersect(array_keys(self::FILE_TYPES), $this->accepted_types ?? []));

        return $types ?: self::DEFAULT_FILE_TYPES;
    }

    /** @return array<int, string> content types for the upload box and the server check */
    public function acceptedMimes(): array
    {
        return array_values(array_unique(array_merge(...array_map(fn ($type) => self::FILE_TYPES[$type]['mimes'], $this->fileTypes()))));
    }

    public function acceptsMime(?string $mime): bool
    {
        return in_array($mime, $this->acceptedMimes(), true);
    }

    public function fileTypesLabel(): string
    {
        return implode(', ', array_map(fn ($type) => self::FILE_TYPES[$type]['short'], $this->fileTypes()));
    }

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
