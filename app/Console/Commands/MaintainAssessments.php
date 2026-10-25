<?php

namespace App\Console\Commands;

use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Services\FinalizeAssessmentAttempt;
use App\Services\StudentDatabase\DatabaseSnapshots;
use App\Services\StudentDatabase\StudentDbServer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class MaintainAssessments extends Command
{
    protected $signature = 'classroom:assessments-maintain {--limit=200 : Maximum expired attempts and snapshots to process}';

    protected $description = 'Finalize expired attempts and retry protected database snapshots without requiring an open browser';

    public function handle(FinalizeAssessmentAttempt $finalizer, DatabaseSnapshots $snapshots): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $closed = $ready = $failed = 0;
        foreach (QuizAttempt::where('status', 'in_progress')->whereNotNull('expires_at')->where('expires_at', '<=', now())->orderBy('expires_at')->limit($limit)->get() as $attempt) {
            try {
                $finalizer->handle($attempt, 'expired');
                $closed++;
            } catch (Throwable $e) {
                report($e);
                $failed++;
            }
        }
        foreach (QuizAnswer::whereNotNull('database_name')->whereHas('attempt', fn ($q) => $q->where('status', '!=', 'in_progress'))
            ->where(fn ($q) => $q->whereNull('database_snapshot_status')->orWhereIn('database_snapshot_status', ['pending', 'failed']))
            ->orderBy('database_snapshot_attempted_at')->limit($limit)->get() as $answer) {
            $snapshots->capture($answer) ? $ready++ : $failed++;
        }
        // Legacy or restored verified exports may predate per-workspace revocation.
        // Marker files avoid disconnecting a student's other active work every minute.
        $reconciled = 0;
        foreach (QuizAnswer::whereNotNull('database_name')->where('database_snapshot_status', 'ready')
            ->whereHas('attempt', fn ($q) => $q->where('status', '!=', 'in_progress'))->orderBy('id')->lazyById(200) as $answer) {
            if (app(StudentDbServer::class)->isWorkspaceClosed($answer->database_name)) {
                continue;
            }
            $snapshots->capture($answer) ? $reconciled++ : $failed++;
            if ($reconciled + $failed >= $limit) {
                break;
            }
        }
        $this->info("Closed {$closed} expired attempts; recovered {$ready} snapshots; {$failed} need attention.");
        Cache::put('assessment-maintenance:last-run', now()->toIso8601String(), now()->addDay());

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
