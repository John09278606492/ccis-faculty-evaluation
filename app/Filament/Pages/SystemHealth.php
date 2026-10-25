<?php

namespace App\Filament\Pages;

use App\Services\StudentDatabase\StudentDatabases;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SystemHealth extends Page
{
    protected static string $view = 'filament.pages.system-health';

    protected static ?string $title = 'System Health & Recovery';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationIcon = 'heroicon-o-heart';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public function checks(): array
    {
        try {
            DB::connection()->getPdo();
            $database = true;
        } catch (\Throwable $e) {
            $database = false;
        }
        try {
            $sql = app(StudentDatabases::class)->available();
        } catch (\Throwable $e) {
            $sql = false;
        }
        $files = glob(rtrim(config('backup.path'), '\\/').'/ccis-backup-*.zip') ?: [];
        rsort($files);
        $last = $files[0] ?? null;
        $keyRecovery = filled(config('backup.recovery_passphrase')) || filled(env('BACKUP_RECOVERY_PASSPHRASE'));

        return [
            ['label' => 'Application database', 'state' => $database ? 'Available' : 'Unavailable', 'detail' => strtoupper(config('database.default')).' — required for evaluations, students and assessments.'],
            ['label' => 'Student SQL workspaces', 'state' => $sql ? 'Available' : 'Stopped / unavailable', 'detail' => 'Used by SQL coding assessments. Ordinary evaluations remain available independently.'],
            ['label' => 'Assessment maintenance', 'state' => Cache::get('assessment-maintenance:last-run', 'No heartbeat recorded yet'), 'detail' => 'The scheduler closes expired attempts and retries pending snapshots every minute.'],
            ['label' => 'Latest backup', 'state' => $last ? basename($last) : 'No backup found', 'detail' => $last ? 'Created '.date('Y-m-d H:i', filemtime($last)).'. Verify the recovery manifest before moving to another machine.' : 'Run the documented backup process before the first campus pilot.'],
            ['label' => 'Key recovery configured', 'state' => $keyRecovery ? 'Configured' : 'Requires recovery passphrase', 'detail' => 'A new-machine restore requires the original encryption key. Keep its recovery passphrase separately and securely.'],
        ];
    }
}
