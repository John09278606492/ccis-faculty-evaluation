<?php

namespace App\Services;

use App\Events\BellNotificationsChanged;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Keep small bell writes responsive while an atomic bulk import owns SQLite's writer. */
class DatabaseNotificationActions
{
    private function directory(): string
    {
        $connection = DB::connection();

        return 'notification-actions/'.hash('sha256', $connection->getName().'|'.$connection->getDatabaseName());
    }

    private function path(int $userId): string
    {
        return Storage::disk('local')->path($this->directory().'/'.$userId.'.json');
    }

    public function pending(int $userId): array
    {
        $path = $this->path($userId);
        // A worker may remove the journal between an existence check and a read.
        $contents = @file_get_contents($path);
        if ($contents === false) {
            if (! is_file($path)) {
                return [];
            }
            throw new \RuntimeException('Could not read saved notification changes.');
        }

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }

    private function query(int $userId, ?Connection $connection = null)
    {
        return ($connection ?? DB::connection())->table('notifications')
            ->where('notifiable_type', (new User)->getMorphClass())->where('notifiable_id', $userId)->where('data->format', 'filament');
    }

    public function record(int $userId, string $action, ?string $id = null): void
    {
        abort_unless(in_array($action, ['dismiss', 'read', 'unread'], true), 422);
        if ($id !== null && ! Str::isUuid($id)) {
            return;
        }
        // Toasts dispatch the same global close event as database notifications.
        // Never request a database write for a toast or another user's notification.
        $ids = $this->query($userId)->when($id !== null, fn ($q) => $q->where('id', $id))->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        $this->locked($userId, function () use ($userId, $action, $ids) {
            $pending = $this->pending($userId);
            foreach ($ids as $id) {
                if (($pending[$id] ?? null) !== 'dismiss') {
                    $pending[$id] = $action;
                }
            }
            // Persist before trying the write: restarts and page reloads cannot lose it.
            File::replace($this->path($userId), json_encode($pending, JSON_THROW_ON_ERROR), 0600);
        });
        $this->flush($userId);
        $this->announce($userId);
    }

    private function writer(): Connection
    {
        $primary = DB::connection();
        if ($primary->getDriverName() !== 'sqlite' || $primary->getDatabaseName() === ':memory:') {
            return $primary;
        }
        $config = [...$primary->getConfig(), 'busy_timeout' => 100];
        if (config('database.connections.notification_actions') !== $config) {
            config(['database.connections.notification_actions' => $config]);
            DB::purge('notification_actions');
        }

        return DB::connection('notification_actions');
    }

    public function flush(int $userId): void
    {
        if ($this->pending($userId) === [] || DB::connection()->transactionLevel() > 0) {
            return;
        }
        $this->locked($userId, function () use ($userId) {
            $pending = $this->pending($userId);
            foreach (['dismiss', 'read', 'unread'] as $action) {
                $ids = array_keys(array_filter($pending, fn ($value) => $value === $action));
                foreach (array_chunk($ids, 200) as $chunk) {
                    try {
                        $query = $this->query($userId, $this->writer())->whereIn('id', $chunk);
                        $action === 'dismiss' ? $query->delete() : $query->update(['read_at' => $action === 'read' ? now() : null]);
                    } catch (QueryException $e) {
                        if (DB::getDriverName() !== 'sqlite' || ! in_array((int) ($e->errorInfo[1] ?? 0), [5, 6], true)) {
                            throw $e;
                        }

                        // A busy database is temporary. The private journal stays intact.
                        return;
                    }
                    $pending = array_diff_key($pending, array_flip($chunk));
                    File::replace($this->path($userId), json_encode($pending, JSON_THROW_ON_ERROR), 0600);
                }
            }
            if ($pending === []) {
                File::delete($this->path($userId));
            }
        });
    }

    public function flushAll(): void
    {
        $directory = Storage::disk('local')->path($this->directory());
        if (! is_dir($directory)) {
            return;
        }
        foreach (File::files($directory) as $file) {
            if (preg_match('/^([1-9][0-9]*)\.json$/', $file->getFilename(), $matches)) {
                try {
                    $this->flush((int) $matches[1]);
                    $this->announce((int) $matches[1]);
                } catch (\Throwable $e) {
                    // An unrelated bell action must never fail a committed import job.
                    Log::error('Notification changes could not be synchronized', ['user_id' => (int) $matches[1], 'exception' => $e]);
                }
            }
        }
    }

    private function locked(int $userId, callable $work): void
    {
        $path = $this->path($userId);
        File::ensureDirectoryExists(dirname($path), 0700);
        $lock = fopen($path.'.lock', 'c');
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Could not save notification changes.');
            }
            $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function announce(int $userId): void
    {
        try {
            broadcast(new BellNotificationsChanged($userId));
        } catch (\Throwable $e) {
            Log::warning('Notification change could not be announced', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }
}
