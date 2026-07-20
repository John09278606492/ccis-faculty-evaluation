<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassRecordUpload extends Model
{
    protected $fillable = [
        'period',
        'original_filename',
        'file_path',
        'uploaded_by',
    ];

    public const PERIODS = [
        'midterm' => 'Midterm',
        'finalterm' => 'Final Term',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function sheets(): HasMany
    {
        return $this->hasMany(ClassRecordSheet::class);
    }
}
