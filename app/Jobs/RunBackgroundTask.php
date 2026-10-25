<?php

namespace App\Jobs;

use App\Services\BackgroundTasks;
use App\Services\DatabaseNotificationActions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunBackgroundTask implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public string $taskId)
    {
        $this->onConnection('bulk')->onQueue('bulk')->afterCommit();
    }

    public function handle(BackgroundTasks $tasks): void
    {
        try {
            $tasks->run($this->taskId);
        } finally {
            app(DatabaseNotificationActions::class)->flushAll();
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(BackgroundTasks::class)->fail($this->taskId, $exception);
    }
}
