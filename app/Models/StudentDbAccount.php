<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's MySQL account on the separate student database server.
 */
class StudentDbAccount extends Model
{
    protected $fillable = [
        'user_id',
        'mysql_user',
        'password',
    ];

    protected $casts = [
        'password' => 'encrypted',
    ];

    protected $hidden = [
        'password',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
