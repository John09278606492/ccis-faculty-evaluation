<?php

namespace App\Console\Commands;

use App\Models\QuizAnswer;
use App\Services\StudentDatabase\StudentDatabases;
use App\Services\StudentDatabase\StudentDbServer;
use Illuminate\Console\Command;
use Throwable;

class StudentDatabaseCommand extends Command
{
    protected $signature = 'classroom:student-db
        {action : install, serve, stop, status, prune or host-config}
        {--phpmyadmin-port= : With serve: also serve phpMyAdmin on this 127.0.0.1 port (development)}
        {--quiz= : With prune: only this quiz\'s databases}';

    protected $description = 'Manage the separate MySQL server for student database workspaces';

    public function handle(StudentDbServer $server, StudentDatabases $databases): int
    {
        try {
            return match ($this->argument('action')) {
                'install' => $this->install($server),
                'serve' => $server->serve($this->option('phpmyadmin-port') ? (int) $this->option('phpmyadmin-port') : null, fn ($line) => $this->info($line)),
                'stop' => $this->stopServer($server),
                'status' => $this->status($server),
                'prune' => $this->prune($databases),
                'host-config' => $this->hostConfig($server),
                default => $this->invalidAction(),
            };
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }

    protected function install(StudentDbServer $server): int
    {
        $server->install(fn ($line) => $this->line($line));
        $this->info('Student database server installed in '.$server->path());
        $this->line('Start it with the desktop host, or for development: php artisan classroom:student-db serve --phpmyadmin-port=8095');

        return self::SUCCESS;
    }

    protected function stopServer(StudentDbServer $server): int
    {
        $this->info($server->stop() ? 'Student MySQL is shutting down.' : 'Student MySQL is not running.');

        return self::SUCCESS;
    }

    protected function status(StudentDbServer $server): int
    {
        $this->table(['', ''], [
            ['Installed', $server->isInstalled() ? 'yes ('.$server->path().')' : 'no'],
            ['Running', $server->isRunning() ? "yes (127.0.0.1:{$server->port()})" : 'no'],
            ['MySQL found', $server->mysqlBin() ?? 'no'],
            ['phpMyAdmin found', $server->phpMyAdminSource() ?? 'no'],
        ]);

        return self::SUCCESS;
    }

    /**
     * Drop workspace databases whose attempts are graded (or all of one quiz's).
     * Their SQL exports stay with the answers.
     */
    protected function prune(StudentDatabases $databases): int
    {
        $answers = QuizAnswer::query()
            ->whereNotNull('database_name')
            ->whereHas('attempt', fn ($attempt) => $this->option('quiz')
                ? $attempt->where('quiz_id', $this->option('quiz'))->where('status', '!=', 'in_progress')
                : $attempt->where('status', 'graded'))
            ->get();
        $unprotected = $answers->filter(fn ($answer) => ! $answer->hasVerifiedDatabaseSnapshot());
        if ($unprotected->isNotEmpty()) {
            $this->error('No databases were deleted. '.$unprotected->count().' workspace(s) have no verified submission snapshot. Run classroom:assessments-maintain and resolve failed snapshots first.');

            return self::FAILURE;
        }
        foreach ($answers as $answer) {
            $databases->drop($answer->database_name);
            $answer->update(['database_name' => null]);
        }
        $this->info("Dropped {$answers->count()} student database(s).");

        return self::SUCCESS;
    }

    /**
     * What the desktop host needs to run phpMyAdmin and MySQL, as JSON.
     */
    protected function hostConfig(StudentDbServer $server): int
    {
        $installed = $server->isInstalled();
        $running = $installed && $server->isRunning();
        if ($running) {
            // Do not mistake an unrelated process on this port for this classroom's database.
            $server->connect()->query('SELECT 1');
        }
        $this->line(json_encode([
            'installed' => $installed,
            'running' => $running,
            'mysql_port' => $server->port(),
            'phpmyadmin_arguments' => $server->phpMyAdminServerArguments(0),
        ], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    protected function invalidAction(): int
    {
        $this->error('Use install, serve, stop, status, prune or host-config.');

        return self::INVALID;
    }
}
