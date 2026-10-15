<?php

namespace App\Models;

use App\Services\AutoSyncClassRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ClassRecordSheet extends Model
{
    protected static function booted(): void
    {
        static::saved(function (self $sheet) {
            if ($sheet->course_offering_id && filled($sheet->student_id_header) && ($sheet->wasRecentlyCreated || $sheet->wasChanged(['course_offering_id', 'student_id_header']))) {
                DB::afterCommit(function () use ($sheet) {
                    Quiz::where('course_offering_id', $sheet->course_offering_id)->where('auto_sync_class_record', true)
                        ->each(fn ($quiz) => app(AutoSyncClassRecord::class)->sync($quiz->id));
                });
            }
        });
    }

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
