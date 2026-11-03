<?php

namespace App\Console\Commands;

use App\Services\StudentEmailAddress;
use Illuminate\Console\Command;

class FillStudentEmails extends Command
{
    protected $signature = 'students:fill-emails {--rebuild : Also redo addresses that were filled in automatically} {--dry-run : Only count}';

    protected $description = 'Replace placeholder student emails with firstname.lastname@<school domain>';

    public function handle(StudentEmailAddress $addresses): int
    {
        $result = $addresses->backfill((bool) $this->option('rebuild'), (bool) $this->option('dry-run'));
        $this->info(($this->option('dry-run') ? 'Would fill ' : 'Filled ').$result['filled'].' · unchanged '.$result['unchanged'].' · duplicate name '.$result['duplicate'].' · no name '.$result['no_name']);
        foreach ($result['duplicates'] as $line) {
            $this->line('  duplicate: '.$line);
        }

        return self::SUCCESS;
    }
}
