<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\StudentDatabase\StudentDbServer;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

class BackupCommand extends Command
{
    protected $signature = 'classroom:backup
        {--if-due : Skip when today\'s backup already exists}';

    protected $description = 'Back up the database and uploaded files to BACKUP_PATH and delete backups older than BACKUP_KEEP_DAYS';

    public function handle(): int
    {
        $folder = rtrim(config('backup.path'), '\\/');
        File::ensureDirectoryExists($folder);

        if ($this->option('if-due') && glob($folder.'/ccis-backup-'.now()->format('Y-m-d').'-*.zip')) {
            return self::SUCCESS;
        }

        $target = $folder.'/ccis-backup-'.now()->format('Y-m-d-His').'.zip';
        $work = storage_path('app/backup-work-'.uniqid());
        File::ensureDirectoryExists($work);

        try {
            $database = $this->dumpDatabase($work);

            $zip = new ZipArchive;
            if ($zip->open($target.'.part', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("Cannot write to {$folder}.");
            }
            $zip->addFile($database, basename($database));
            foreach (['private', 'public'] as $disk) {
                $this->addFolder($zip, storage_path("app/{$disk}"), "files/{$disk}", $folder);
            }
            if (! $zip->close()) {
                throw new RuntimeException("Cannot finish the backup in {$folder}.");
            }
            rename($target.'.part', $target);
        } catch (Throwable $e) {
            @unlink($target.'.part');
            $this->reportFailure($e->getMessage());

            return self::FAILURE;
        } finally {
            File::deleteDirectory($work);
        }

        $removed = $this->prune($folder, basename($target));
        $this->info('Backup saved to '.$target.($removed ? ", {$removed} old backup(s) removed." : '.'));

        return self::SUCCESS;
    }

    private function dumpDatabase(string $work): string
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        if ($config['driver'] === 'sqlite') {
            $file = $work.'/database.sqlite';
            DB::connection($connection)->statement('VACUUM INTO ?', [$file]);

            return $file;
        }

        if (! in_array($config['driver'], ['mysql', 'mariadb'], true)) {
            throw new RuntimeException("Backups support MySQL and SQLite, not {$config['driver']}.");
        }

        $file = $work.'/database.sql';
        $process = new Process([
            $this->mysqldump(),
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--user='.$config['username'],
            '--single-transaction',
            '--routines',
            '--default-character-set=utf8mb4',
            '--result-file='.$file,
            $config['database'],
        ], null, ['MYSQL_PWD' => (string) $config['password']], null, 1800);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($file)) {
            throw new RuntimeException('mysqldump failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        return $file;
    }

    private function mysqldump(): string
    {
        if ($configured = config('backup.mysqldump')) {
            return $configured;
        }

        $bin = app(StudentDbServer::class)->mysqlBin();

        return $bin ? $bin.DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'mysqldump.exe' : 'mysqldump') : 'mysqldump';
    }

    private function addFolder(ZipArchive $zip, string $source, string $prefix, string $skip): void
    {
        if (! is_dir($source)) {
            return;
        }

        $skip = realpath($skip) ?: $skip;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $path = $file->getRealPath();
            if (! $file->isFile() || str_starts_with($path, $skip.DIRECTORY_SEPARATOR)) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
            $zip->addFile($path, $prefix.'/'.$relative);
        }
    }

    /**
     * Delete backups older than the retention window, always keeping the newest one.
     */
    private function prune(string $folder, string $newest): int
    {
        $cutoff = now()->subDays(max(1, (int) config('backup.keep_days')))->getTimestamp();
        $removed = 0;

        foreach (glob($folder.'/ccis-backup-*.zip') ?: [] as $file) {
            if (basename($file) !== $newest && filemtime($file) < $cutoff && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function reportFailure(string $message): void
    {
        $this->error('Backup failed: '.$message);
        Log::error('Backup failed', ['message' => $message]);

        try {
            $admins = User::role('super_admin')->get();
        } catch (Throwable) {
            return;
        }
        if ($admins->isNotEmpty()) {
            Notification::make()
                ->title('Nightly backup failed')
                ->body($message)
                ->danger()
                ->sendToDatabase($admins);
        }
    }
}
