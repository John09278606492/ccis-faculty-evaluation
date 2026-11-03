<?php

use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
// Keep every due task inside this supervised process. A deployment waits for it
// rather than abandoning detached scheduler children that still run old code.
$app->make('events')->listen(ScheduledTaskStarting::class,
    function ($event) {
        $event->task->runInBackground = false;
    });
$input = new ArrayInput(['command' => 'schedule:run']);
$status = $kernel->handle($input, new ConsoleOutput);
$kernel->terminate($input, $status);
exit($status);
