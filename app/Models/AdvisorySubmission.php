<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvisorySubmission extends Model
{
    public const STATUSES = ['pending' => 'Not submitted', 'submitted' => 'Submitted', 'approved' => 'Approved', 'returned' => 'Returned'];

    public const COLORS = ['pending' => 'gray', 'submitted' => 'info', 'approved' => 'success', 'returned' => 'warning'];

    protected $fillable = ['advisory_task_id', 'user_id', 'student_name', 'status', 'files', 'note', 'submitted_at', 'feedback', 'reviewed_by', 'reviewed_at'];

    protected $casts = ['files' => 'array', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(AdvisoryTask::class, 'advisory_task_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withoutGlobalScope('assigned_data')->withTrashed();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withoutGlobalScope('assigned_data')->withTrashed();
    }
}
