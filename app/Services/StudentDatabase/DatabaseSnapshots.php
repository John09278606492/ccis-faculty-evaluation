<?php

namespace App\Services\StudentDatabase;

use App\Models\QuizAnswer;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class DatabaseSnapshots
{
    public function capture(QuizAnswer $answer): bool
    {
        try {
            return Cache::lock('student-db-answer-'.$answer->id, 300)->block(5, function () use ($answer) {
                $answer->refresh()->loadMissing('attempt');
                if (! $answer->database_name || $answer->attempt->status === 'in_progress') {
                    return false;
                }
                $databases = app(StudentDatabases::class);
                try {
                    // Even an already-captured legacy answer needs its old writable account revoked.
                    if (! $databases->available()) {
                        throw new RuntimeException('Student database service is unavailable. Retry after it is restored.');
                    }
                    $databases->freeze($answer);
                    if ($answer->hasVerifiedDatabaseSnapshot()) {
                        return true;
                    }
                    // Never replace evidence whose integrity failed with a different live copy.
                    if ($answer->database_snapshot_status === 'ready' || $answer->database_snapshot_hash) {
                        throw new RuntimeException('The saved snapshot failed its integrity check. Preserve the workspace and investigate before retrying.');
                    }
                    $answer->update(['database_snapshot_status' => 'pending', 'database_snapshot_attempted_at' => now()]);
                    $sql = $databases->export($answer);
                    if (! is_string($sql) || trim($sql) === '') {
                        throw new RuntimeException('The student database export was empty. The workspace was retained for recovery.');
                    }
                    $answer->update([
                        'database_export' => $sql, 'database_snapshot_hash' => hash('sha256', $sql),
                        'database_snapshot_status' => 'ready', 'database_snapshot_at' => now(),
                        'database_snapshot_attempted_at' => now(), 'database_snapshot_error' => null,
                    ]);

                    return true;
                } catch (Throwable $error) {
                    report($error);
                    // Existing verified evidence remains ready during a service outage.
                    if (! $answer->hasVerifiedDatabaseSnapshot()) {
                        $answer->update(['database_snapshot_status' => 'failed', 'database_snapshot_attempted_at' => now(),
                            'database_snapshot_error' => mb_substr($error->getMessage(), 0, 2000)]);
                    }

                    return false;
                }
            });
        } catch (LockTimeoutException $error) {
            report($error);

            // Another worker is already capturing this answer; its lock protects evidence.
            return false;
        }
    }
}
