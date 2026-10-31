<?php

namespace App\Models;

use App\Services\EvaluationInstrument;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_id',
        'user_id',
        'submission_fingerprint',
        'department_id',
        'course_offering_id', // Track which course offering was evaluated
        'data',
        'ip_address',
        'user_agent',
        'submitted_at',
        'evaluator_identity',
        'instrument_snapshot',
    ];

    protected $casts = [
        'evaluator_identity' => 'encrypted:array',
        'data' => 'array',
        'instrument_snapshot' => 'array',
        'submitted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $submission) {
            if (! $submission->instrument_snapshot && ($form = Form::find($submission->form_id))) {
                $submission->instrument_snapshot = EvaluationInstrument::snapshot($form);
            }
        });
    }

    protected $hidden = ['evaluator_identity'];

    /**
     * Get the course offering this submission is for (if evaluating an instructor).
     */
    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class);
    }

    /**
     * Get the form this submission belongs to.
     */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /**
     * Get the user who submitted the form (if logged in).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Get the department associated with this submission.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Get a specific field value from the submission data.
     */
    public function getFieldValue(string $fieldName): mixed
    {
        return $this->data[$fieldName] ?? null;
    }

    /**
     * Get the submitter name (user name or "Anonymous").
     */
    public function getSubmitterNameAttribute(): string
    {
        return $this->user?->name ?? 'Anonymous';
    }
}
