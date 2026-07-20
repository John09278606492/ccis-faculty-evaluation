<?php

namespace App\Models;

use App\Traits\NormalizesTextFields;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolYear extends Model
{
    use HasFactory, NormalizesTextFields;

    protected $fillable = [
        'campus_id',
        'name',
        'start_date',
        'end_date',
        'is_current',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_current' => 'boolean',
    ];

    protected static function booted(): void
    {
        // When a School Year is marked as current, deactivate all others for the same campus
        static::saving(function (SchoolYear $schoolYear) {
            if ($schoolYear->is_current && $schoolYear->isDirty('is_current')) {
                static::where('campus_id', $schoolYear->campus_id)
                    ->where('id', '!=', $schoolYear->id ?? 0)
                    ->where('is_current', true)
                    ->update(['is_current' => false]);
            }
        });
    }

    /**
     * Check if there is an active (current) school year for the given campus
     * where today's date falls within the start and end dates.
     */
    public static function hasActiveForCampus(int $campusId): bool
    {
        return static::where('campus_id', $campusId)
            ->where('is_current', true)
            ->where('start_date', '<=', Carbon::today())
            ->where('end_date', '>=', Carbon::today())
            ->exists();
    }

    /**
     * Get the currently active school year for a campus, if any.
     */
    public static function getActiveForCampus(int $campusId): ?self
    {
        return static::where('campus_id', $campusId)
            ->where('is_current', true)
            ->where('start_date', '<=', Carbon::today())
            ->where('end_date', '>=', Carbon::today())
            ->first();
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function semesters(): HasMany
    {
        return $this->hasMany(Semester::class);
    }
}
