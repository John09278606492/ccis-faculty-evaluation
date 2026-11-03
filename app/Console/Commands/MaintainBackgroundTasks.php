<?php

namespace App\Console\Commands;

use App\Models\BackgroundTask;
use App\Services\BackgroundTasks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MaintainBackgroundTasks extends Command
{
    protected $signature = 'background:maintain';

    protected $description = 'Fail abandoned operations and remove expired private bulk files';

    public function handle(BackgroundTasks $tasks): int
    {
        BackgroundTask::where(fn ($q) => $q->where('status', 'processing')->where('started_at', '<', now()->subMinutes(30)))
            ->orWhere(fn ($q) => $q->where('status', 'queued')->where('created_at', '<', now()->subDay()))
            ->eachById(fn ($task) => $tasks->fail($task->id, null));
        BackgroundTask::whereIn('status', ['completed', 'failed'])->where('completed_at', '<', now()->subDays(7))->eachById(function ($task) {
            $directories = ['background-tasks/'.$task->id];
            if ($source = $task->payload['source'] ?? null) {
                $directories[] = dirname($source);
            }
            foreach (array_unique($directories) as $directory) {
                if (preg_match('#^background-tasks/[a-f0-9-]{36}$#i', $directory)) {
                    Storage::disk('local')->deleteDirectory($directory);
                }
            }
            $task->delete();
        });
        $this->info('Background processing maintenance completed.');

        return self::SUCCESS;
    }
}
