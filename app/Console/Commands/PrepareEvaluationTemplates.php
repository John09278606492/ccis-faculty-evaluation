<?php

namespace App\Console\Commands;

use App\Models\Campus;
use App\Services\EvaluationTemplates;
use Illuminate\Console\Command;

class PrepareEvaluationTemplates extends Command
{
    protected $signature = 'evaluation:prepare-templates';

    protected $description = 'Prepare editable Annex A and B drafts for each campus without changing existing assignments';

    public function handle(EvaluationTemplates $templates): int
    {
        foreach (Campus::all() as $campus) {
            foreach (EvaluationTemplates::NAMES as $annex => $name) {
                if ($campus->forms()->where('name', $name)->exists()) {
                    continue;
                }
                $templates->create($annex, $campus);
                $this->info($campus->name.': '.$name);
            }
        }

        return self::SUCCESS;
    }
}
