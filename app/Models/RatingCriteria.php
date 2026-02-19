<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\NormalizesTextFields;

class RatingCriteria extends Model
{
    use HasFactory, NormalizesTextFields;

    protected $table = 'rating_criteria';

    protected $fillable = [
        'campus_id',
        'name',
        'description',
        'weight',
        'order',
        'is_active',
    ];

    protected $casts = [
        'weight' => 'decimal:2',
        'order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }
}
