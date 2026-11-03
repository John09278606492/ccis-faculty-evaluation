<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationReminder extends Model
{
    protected $fillable = ['campus_id', 'sender_id', 'user_id', 'academic_section_id', 'remaining', 'subject', 'message', 'email', 'email_status', 'email_error', 'emailed_at'];

    protected $casts = ['emailed_at' => 'datetime', 'remaining' => 'integer'];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }
}
