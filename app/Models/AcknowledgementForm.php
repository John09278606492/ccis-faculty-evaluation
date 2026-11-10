<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcknowledgementForm extends Model
{
    public const STATUSES = [
        'active' => 'Active: visible and signable',
        'closed' => 'Closed: visible but not signable',
        'archived' => 'Archived: hidden from students',
    ];

    protected $fillable = [
        'course_offering_id',
        'document_title',
        'document_received',
        'date_received',
        'status',
        'year_section',
        'college_name',
        'department_name',
        'term_label',
        'instructor_name',
        'instructor_designation',
        'created_by',
    ];

    protected $casts = [
        'date_received' => 'date',
    ];

    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class);
    }

    /** Students on the receipt. Students the instructor removed are left out of lists, counts and the printout. */
    public function recipients(): HasMany
    {
        return $this->hasMany(AcknowledgementRecipient::class)->whereNull('acknowledgement_recipients.removed_at');
    }

    public function removedRecipients(): HasMany
    {
        return $this->hasMany(AcknowledgementRecipient::class)->whereNotNull('acknowledgement_recipients.removed_at');
    }

    public function scopeInCampus(Builder $query, ?int $campusId): Builder
    {
        return $query->whereHas('courseOffering.semester.schoolYear', fn ($q) => $q->where('campus_id', $campusId));
    }

    public function isOpen(): bool
    {
        return $this->status === 'active';
    }

    public function getDateLabelAttribute(): string
    {
        return $this->date_received->format('F j, Y');
    }
}
