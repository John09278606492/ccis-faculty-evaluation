<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rating extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_offering_id',
        'user_id',
        'rating_criteria_id',
        'score',
        'comment',
        'submitted_at',
    ];

    protected $casts = [
        'score' => 'integer',
        'submitted_at' => 'datetime',
    ];

    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ratingCriteria(): BelongsTo
    {
        return $this->belongsTo(RatingCriteria::class);
    }

    /**
     * Get the campus through relationships.
     */
    public function getCampusAttribute()
    {
        return $this->courseOffering?->campus;
    }
}
