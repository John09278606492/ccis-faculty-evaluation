<?php

// Bounded, non-overlapping jobs for a PHP hosting account; never a web endpoint.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

$mode = $argv[1] ?? '';
if (! in_array($mode, ['queue', 'bulk', 'schedule'], true)) {
    fwrite(STDERR, "Use queue, bulk or schedule.\n");
    exit(1);
}

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->usePublicPath(dirname($root).'/public_html');
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$lock = fopen(storage_path('framework/hostinger-'.$mode.'.lock'), 'c');
if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

try {
    if ($mode === 'schedule') {
        // Keep scheduled tasks supervised by this cron process, including backups.
        $app->make('events')->listen(ScheduledTaskStarting::class, function ($event) {
            $event->task->runInBackground = false;
        });
        $arguments = ['command' => 'schedule:run'];
    } else {
        $bulk = $mode === 'bulk';
        $arguments = ['command' => 'queue:work', 'connection' => $bulk ? 'bulk' : 'database',
            '--queue' => $bulk ? 'bulk' : 'default', '--stop-when-empty' => true,
            '--max-time' => 45, '--max-jobs' => $bulk ? 1 : 50,
            '--sleep' => 1, '--tries' => 1, '--timeout' => $bulk ? 900 : 60];
    }
    $input = new ArrayInput($arguments);
    $status = $kernel->handle($input, new ConsoleOutput);
    $kernel->terminate($input, $status);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
exit($status);
