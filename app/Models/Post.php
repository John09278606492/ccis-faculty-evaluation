<?php

namespace App\Models;

use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'content',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['created_at', 'updated_at'];

    /**
     * Get the campus that owns this post.
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }
}
