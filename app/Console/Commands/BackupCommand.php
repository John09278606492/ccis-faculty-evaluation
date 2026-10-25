<?php

namespace App\Console\Commands;

use App\Models\QuizAnswer;
use App\Models\User;
use App\Notifications\BellNotification;
use App\Services\BackupRecovery;
use App\Services\StudentDatabase\StudentDatabases;
use App\Services\StudentDatabase\StudentDbServer;
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

    private array $entries = [];

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
            $this->entries = [];
            $this->archiveFile($zip, $database, basename($database));
            foreach (['private', 'public'] as $disk) {
                $this->addFolder($zip, storage_path("app/{$disk}"), "files/{$disk}", $folder);
            }
            $workspaces = $this->addWorkspaces($zip, $work);
            $key = (string) config('app.key');
            $passphrase = config('backup.recovery_passphrase');
            if (is_string($passphrase) && $passphrase !== '') {
                $envelope = app(BackupRecovery::class)->protectKey($key, $passphrase);
                $name = 'recovery/application-key.enc.json';
                $zip->addFromString($name, $envelope);
                $this->entries[$name] = ['sha256' => hash('sha256', $envelope), 'bytes' => strlen($envelope)];
            } else {
                $this->warn('Recovery passphrase not configured: this data backup requires the original APP_KEY kept separately. Configure BACKUP_RECOVERY_PASSPHRASE for a protected portable key envelope.');
            }
            $manifest = ['version' => 2, 'created_at' => now()->toIso8601String(), 'main_database' => basename($database),
                'database_driver' => config('database.connections.'.config('database.default').'.driver'),
                'application_cipher' => config('app.cipher'), 'application_key_fingerprint' => hash('sha256', $key), 'protected_key_included' => isset($envelope),
                'workspaces' => $workspaces, 'entries' => $this->entries];
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            if (! $zip->close()) {
                throw new RuntimeException("Cannot finish the backup in {$folder}.");
            }
            app(BackupRecovery::class)->inspect($target.'.part');
            if (! rename($target.'.part', $target)) {
                throw new RuntimeException('The verified backup could not be finalized.');
            }
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
            $this->archiveFile($zip, $path, $prefix.'/'.$relative);
        }
    }

    private function archiveFile(ZipArchive $zip, string $path, string $name): void
    {
        $zip->addFile($path, $name);
        $this->entries[$name] = ['sha256' => hash_file('sha256', $path), 'bytes' => filesize($path)];
    }

    private function addWorkspaces(ZipArchive $zip, string $work): array
    {
        $answers = QuizAnswer::whereNotNull('database_name')->with('attempt')->get();
        $workspaces = [];
        $databases = app(StudentDatabases::class);
        foreach ($answers as $answer) {
            $name = $answer->database_name;
            if (! preg_match('/^stu\d+_[A-Za-z0-9_]+$/', $name)) {
                throw new RuntimeException('Unexpected workspace name; backup was not completed.');
            }
            $captured = now()->toIso8601String();
            if ($answer->attempt->status !== 'in_progress' && $answer->hasVerifiedDatabaseSnapshot()) {
                $sql = $answer->database_export;
                $source = 'verified_submission';
            } else {
                if (! $databases->available()) {
                    throw new RuntimeException('A live SQL workspace has no recoverable snapshot. Start the student database service and retry the backup.');
                }
                $sql = $databases->export($answer);
                $source = 'live_workspace';
            }
            if (! is_string($sql) || trim($sql) === '') {
                throw new RuntimeException('An SQL workspace export was empty; the backup was not completed.');
            }
            $file = $work.'/'.$name.'.sql';
            file_put_contents($file, $sql);
            $entry = 'workspaces/'.$name.'.sql';
            $this->archiveFile($zip, $file, $entry);
            $workspaces[] = ['database' => $name, 'answer_id' => $answer->id, 'attempt_status' => $answer->attempt->status,
                'entry' => $entry, 'source' => $source, 'captured_at' => $captured];
        }

        return $workspaces;
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
            BellNotification::make()
                ->title('Nightly backup failed')
                ->body($message)
                ->danger()
                ->sendToDatabase($admins);
        }
    }
}
