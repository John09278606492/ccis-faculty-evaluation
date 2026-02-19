<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Book extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\BookFactory> */
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'title',
        'author',
        'description',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['created_at', 'updated_at'];

    /**
     * Get the campus that owns this book.
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this
            ->addMediaConversion('preview')
            ->fit(Fit::Contain, 300, 300)
            ->nonQueued();
    }
}

