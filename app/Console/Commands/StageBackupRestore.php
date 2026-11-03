<?php

namespace App\Console\Commands;

use App\Services\BackupRecovery;
use Illuminate\Console\Command;
use Throwable;

class StageBackupRestore extends Command
{
    protected $signature = 'classroom:restore {archive : Backup ZIP to verify} {--stage= : New absolute directory to stage verified files} {--recover-key : Ask privately for the recovery passphrase and recover the original key in the staging directory}';

    protected $description = 'Verify a recovery bundle and stage a restore without overwriting the live database';

    public function handle(BackupRecovery $recovery): int
    {
        try {
            if ($this->option('recover-key') && ! $this->option('stage')) {
                throw new \RuntimeException('Use --stage with --recover-key. Keys are never printed to the terminal.');
            }
            $passphrase = $this->option('recover-key') ? $this->secret('Recovery passphrase') : null;
            $manifest = $this->option('stage')
                ? $recovery->stage($this->argument('archive'), $this->option('stage'), $passphrase)
                : $recovery->inspect($this->argument('archive'));
            $this->info('Backup integrity verified. '.count($manifest['entries']).' files and '.count($manifest['workspaces']).' SQL workspaces.');
            $this->line('Original-key recovery: '.($manifest['protected_key_included'] ? 'protected envelope included' : 'original APP_KEY must be supplied separately'));
            if ($this->option('stage')) {
                $this->info('Staged files and RESTORE-INSTRUCTIONS.md in '.$this->option('stage').'. No live data was changed.');
            }

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
