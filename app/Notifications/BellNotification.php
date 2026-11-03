<?php

namespace App\Notifications;

use App\Events\BellNotificationsChanged;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Persist bell notifications immediately and announce them after the transaction
 * commits, so another open browser can read the notification when signaled.
 */
class BellNotification extends Notification
{
    public function sendToDatabase(Model|Authenticatable|Collection|array $users, bool $isEventDispatched = true): static
    {
        if (! is_iterable($users)) {
            $users = [$users];
        }

        foreach ($users as $user) {
            $user->notifyNow($this->toDatabase());

            if ($isEventDispatched) {
                DB::afterCommit(function () use ($user) {
                    try {
                        broadcast(new BellNotificationsChanged($user->getKey()));
                    } catch (\Throwable $e) {
                        Log::warning('Live update could not be delivered', ['kind' => 'bell', 'error' => $e->getMessage()]);
                    }
                });
            }
        }

        return $this;
    }
}
