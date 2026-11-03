<?php

namespace App\Services;

use App\Models\BackgroundTask;
use App\Models\ClassRecordUpload;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class BackgroundWorkbookUpload
{
    public function run(BackgroundTask $task, User $actor): array
    {
        $data = $task->payload;
        Validator::make($data, ['period' => ['required', Rule::in(array_keys(ClassRecordUpload::PERIODS))]])->validate();
        $disk = Storage::disk('local');
        $reader = IOFactory::createReaderForFile($disk->path($data['source']));
        $names = array_values(array_filter($reader->listWorksheetNames($disk->path($data['source'])), fn ($name) => stripos($name, 'transmutation') === false));
        if ($names === []) {
            throw ValidationException::withMessages(['file' => 'No class worksheets were found in the workbook.']);
        }
        $path = 'class-records/'.$task->id.'/workbook.'.pathinfo($data['source'], PATHINFO_EXTENSION);
        abort_unless($disk->copy($data['source'], $path), 500);
        try {
            DB::transaction(function () use ($actor, $data, $path, $names) {
                $upload = ClassRecordUpload::create(['uploaded_by' => $actor->id, 'period' => $data['period'], 'original_filename' => basename($data['original_filename']), 'file_path' => $path]);
                foreach ($names as $name) {
                    $upload->sheets()->create(['sheet_name' => $name, 'period' => $data['period'], 'student_id_header' => '']);
                }
            });
        } catch (\Throwable $e) {
            $disk->delete($path);
            throw $e;
        }
        ClassroomUpdates::panel('workbook', true);

        return ['message' => count($names).' worksheets uploaded. Open Class Records to map them to your teaching classes.'];
    }
}
