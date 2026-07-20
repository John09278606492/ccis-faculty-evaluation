<?php

namespace App\Models;

use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    protected $fillable = ['name'];

    protected $guarded = ['id'];

    protected $hidden = ['created_at', 'updated_at'];

    /**
     * Get the campus that owns this contact.
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }
}
