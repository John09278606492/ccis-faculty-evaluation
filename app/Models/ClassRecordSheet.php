<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassRecordSheet extends Model
{
    protected $fillable = [
        'class_record_upload_id',
        'course_offering_id',
        'sheet_name',
        'period',
        'student_id_header',
    ];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(ClassRecordUpload::class, 'class_record_upload_id');
    }

    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class);
    }

    public function quizColumns(): HasMany
    {
        return $this->hasMany(ClassRecordQuizColumn::class);
    }
}
