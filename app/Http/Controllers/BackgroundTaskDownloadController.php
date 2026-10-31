<?php

namespace App\Http\Controllers;

use App\Models\BackgroundTask;
use App\Models\Campus;
use App\Services\AssessmentBulkFiles;
use App\Services\BackgroundExports;
use App\Services\BackgroundTasks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class BackgroundTaskDownloadController extends Controller
{
    public function __invoke(Request $request, BackgroundTask $task, BackgroundTasks $tasks)
    {
        $campus = Campus::findOrFail($task->campus_id);
        $tasks->authorizeTask($task, $request->user(), $campus);
        abort_unless($task->status === 'completed' && $task->completed_at->gt(now()->subDays(7)) && $task->file_path, 410, 'This download is not ready or has expired. Generate it again.');
        $tasks->inContext($request->user(), $campus, function () use ($task, $request, $campus) {
            if (str_starts_with($task->kind, 'assessment-')) {
                app(AssessmentBulkFiles::class)->authorizeDownload($task, $request->user(), $campus);
            } elseif (str_ends_with($task->kind, '-export')) {
                app(BackgroundExports::class)->authorizeDownload($task, $request->user(), $campus);
            }
        });
        abort_unless(Storage::disk('local')->exists($task->file_path), 410);
        if ($task->result['encrypted'] ?? false) {
            return response()->streamDownload(fn () => print (Crypt::decryptString(Storage::disk('local')->get($task->file_path))), $task->filename,
                ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }

        return Storage::disk('local')->download($task->file_path, $task->filename, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
