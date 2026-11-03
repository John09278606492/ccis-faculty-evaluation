<?php

namespace App\Console\Commands;

use App\Models\EvaluationCycle;
use App\Services\ClassroomUpdates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class MaintainEvaluations extends Command
{
    protected $signature = 'classroom:evaluations-maintain';

    protected $description = 'Close published evaluations whose configured closing time has passed';

    public function handle(): int
    {
        $closed = EvaluationCycle::withoutGlobalScopes()->where('status', 'published')->whereNotNull('ends_at')->where('ends_at', '<=', now())
            ->update(['status' => 'closed', 'closed_at' => now(), 'updated_at' => now()]);
        if ($closed) {
            ClassroomUpdates::panel('setup');
        }
        Cache::put('evaluation-maintenance:last-run', now()->toIso8601String(), now()->addHours(2));
        $this->info($closed.' scheduled evaluations closed. Evaluations without a closing date remain open.');

        return self::SUCCESS;
    }
}
