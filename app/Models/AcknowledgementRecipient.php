<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcknowledgementRecipient extends Model
{
    /** Everything except the signature drawing, for lists that only need the status. */
    public const LIST_COLUMNS = ['id', 'acknowledgement_form_id', 'user_id', 'student_name', 'signed_at', 'signed_by'];

    protected $fillable = [
        'acknowledgement_form_id',
        'user_id',
        'student_name',
        'signature_path',
        'signed_at',
        'signed_by',
        'signed_ip',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
    ];

    protected $hidden = ['signature_path', 'signed_ip'];

    public function form(): BelongsTo
    {
        return $this->belongsTo(AcknowledgementForm::class, 'acknowledgement_form_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withoutGlobalScope('assigned_data')->withTrashed();
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by')->withoutGlobalScope('assigned_data')->withTrashed();
    }
}
