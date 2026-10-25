<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationCycle extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'published_at' => 'datetime', 'closed_at' => 'datetime', 'archived_at' => 'datetime'];

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function studentForm(): BelongsTo
    {
        return $this->belongsTo(Form::class, 'student_form_id');
    }

    public function supervisorForm(): BelongsTo
    {
        return $this->belongsTo(Form::class, 'supervisor_form_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CourseOffering::class);
    }
}
