<?php

namespace App\Console\Commands;

use App\Services\SqliteMigration;
use Illuminate\Console\Command;

class CopyToSqlite extends Command
{
    protected $signature = 'classroom:sqlite-copy {destination : New absolute SQLite file; source is unchanged}';

    protected $description = 'Copy the current database into SQLite and verify every table, raw value, and foreign key';

    public function handle(SqliteMigration $migration): int
    {
        try {
            $report = $migration->copy($this->argument('destination'));
            $this->info('Verified '.count($report['tables']).' tables and '.array_sum(array_column($report['tables'], 'rows')).' rows. Source database and APP_KEY unchanged.');
            $this->line('Verification manifest: '.$this->argument('destination').'.verification.json');

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
