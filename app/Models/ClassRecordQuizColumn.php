<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassRecordQuizColumn extends Model
{
    protected $fillable = [
        'class_record_sheet_id',
        'quiz_id',
        'column_letter',
    ];

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(ClassRecordSheet::class, 'class_record_sheet_id');
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }
}
